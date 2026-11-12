<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Audit\Actor;
use App\Infrastructure\Db\SqlText;
use App\Models\User;
use App\Platform\Operator;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use stdClass;

/**
 * A booking's audit trail as the booking dialog shows it: oldest first, each entry's instant in
 * the API's spelling for the client to turn into the tenant's zone, the payload as the facts it
 * carries, and the actor by kind. A user actor is named by name, resolved from the central users
 * table; a support actor by the operator's name, resolved from the central operators table.
 * Which facts reach the dialog is BookingPayload's decision, TRAIL_FACTS, as every other
 * surface's is.
 *
 * One statement per side: the trail from the tenant's schema, the names from the central one,
 * one read of users and one of operators, each only where the trail names one, joined here and
 * never by a foreign key across schemas. A user of another tenant, or one since deleted, has no
 * name here and reads as the raw actor; an operator since removed has no name either and reads
 * as support alone.
 *
 * A move by the business names the members it gave segments to by id, staff_changed, as the
 * trail names nobody; the members' names are read here, one statement over the tenant's staff
 * and only where a row names one, and set beside the ids as from_name and to_name, null for a
 * member since deleted.
 *
 * Beside one booking's trail, the tenant's latest rows for Home's Recent activity (latest), each
 * joined to its booking, the names resolved the same way.
 */
final readonly class BookingTrail
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * @return list<array{action: string, actor: string, kind: string, name: string|null, at: string, facts: array<mixed>}>
     */
    public function of(Tenant $tenant, string $uuid): array
    {
        $rows = $this->db->connection()
            ->table($tenant->schema_name.'.audit_log', 'entry')
            ->join($tenant->schema_name.'.bookings as booking', 'booking.id', '=', 'entry.booking_id')
            ->where('booking.uuid', $uuid)
            ->orderBy('entry.created_at')
            ->orderBy('entry.id')
            ->selectRaw('entry.actor, entry.action, entry.payload::text as payload, entry.created_at::text as created_at')
            ->get();

        return $this->entries($tenant, array_values($rows->all()));
    }

    /**
     * The tenant's latest trail rows, newest first, for Home's Recent activity: one statement
     * over audit_log by created_at with the limit, each row joined to its booking for the uuid,
     * the customer's name and the first start, a row whose booking is gone answering none of the
     * three; then the names as of() reads them. The facts are the dialog's, TRAIL_FACTS, and
     * Home carries none of them onto a row, so a paid amount never reaches it. No index serves
     * the order: the scan is bounded by the table, which the retention sweep keeps to 24 months.
     *
     * @param  positive-int  $limit
     * @return list<array{action: string, actor: string, kind: string, name: string|null, at: string, facts: array<mixed>, booking: array{uuid: string, customer: string|null, starts_at: string|null}|null}>
     */
    public function latest(Tenant $tenant, int $limit): array
    {
        $schema = $tenant->schema_name;
        $rows = $this->db->connection()
            ->table($schema.'.audit_log', 'entry')
            ->leftJoin($schema.'.bookings as booking', 'booking.id', '=', 'entry.booking_id')
            ->leftJoinSub(SqlText::firstStarts($schema), 'first', 'first.booking_id', '=', 'entry.booking_id')
            ->orderByDesc('entry.created_at')
            ->orderByDesc('entry.id')
            ->limit($limit)
            ->selectRaw('entry.actor, entry.action, entry.payload::text as payload, entry.created_at::text as created_at,'
                .' booking.uuid, booking.customer_name, first.starts_at::text as starts_at')
            ->get()
            ->all();
        $rows = array_values($rows);
        $latest = [];

        foreach ($this->entries($tenant, $rows) as $index => $entry) {
            $row = $rows[$index];
            $latest[] = [...$entry, 'booking' => is_string($row->uuid) ? [
                'uuid' => $row->uuid,
                'customer' => is_string($row->customer_name) ? $row->customer_name : null,
                'starts_at' => is_string($row->starts_at) ? BookingPayload::instant(SqlText::readInstant($row->starts_at)) : null,
            ] : null];
        }

        return $latest;
    }

    /**
     * The rows as the trail's entries, in their order: each actor by kind and by the name it
     * carries, a user's from the central users table and an operator's from the central
     * operators table, one read each and only where a row names one.
     *
     * @param  list<stdClass>  $rows
     * @return list<array{action: string, actor: string, kind: string, name: string|null, at: string, facts: array<mixed>}>
     */
    private function entries(Tenant $tenant, array $rows): array
    {
        $entries = [];
        $users = [];
        $operators = [];

        foreach ($rows as $row) {
            if (! is_string($row->actor) || ! is_string($row->action) || ! is_string($row->payload) || ! is_string($row->created_at)) {
                throw new LogicException(sprintf('An audit_log row of tenant "%s" is not shaped as the schema declares.', $tenant->slug));
            }

            $actor = self::actor($row->actor);
            $userId = $actor?->userId();

            if ($userId !== null) {
                $users[$userId] = null;
            }

            $operatorId = $actor?->supportOperatorId();

            if ($operatorId !== null) {
                $operators[$operatorId] = null;
            }

            $facts = json_decode($row->payload, true);
            $entries[] = [
                'action' => $row->action,
                'actor' => $row->actor,
                'kind' => self::kind($row->actor),
                'user' => $userId,
                'operator' => $operatorId,
                'at' => BookingPayload::instant(SqlText::readInstant($row->created_at)),
                'facts' => is_array($facts) ? BookingPayload::trailFacts($facts) : [],
            ];
        }

        $names = $users === [] ? [] : User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', array_keys($users))
            ->pluck('name', 'id')
            ->all();

        $operatorNames = $operators === [] ? [] : Operator::query()
            ->whereIn('id', array_keys($operators))
            ->pluck('name', 'id')
            ->all();

        $staffNames = $this->staffNames($tenant, $entries);
        $trail = [];

        foreach ($entries as $entry) {
            $name = match (true) {
                $entry['user'] !== null => $names[$entry['user']] ?? null,
                $entry['operator'] !== null => $operatorNames[$entry['operator']] ?? null,
                default => null,
            };
            $trail[] = [
                'action' => $entry['action'],
                'actor' => $entry['actor'],
                'kind' => $entry['kind'],
                'name' => is_string($name) ? $name : null,
                'at' => $entry['at'],
                'facts' => self::namedStaff($entry['facts'], $staffNames),
            ];
        }

        return $trail;
    }

    /**
     * The names of the members the trail's staff changes name, by id, one read of the tenant's
     * staff and none where no entry names one.
     *
     * @param  list<array{facts: array<mixed>}>  $entries
     * @return array<int, string>
     */
    private function staffNames(Tenant $tenant, array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            foreach (self::changes($entry['facts']) as $change) {
                $ids[$change['from']] = true;
                $ids[$change['to']] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach ($this->db->connection()->table($tenant->schema_name.'.staff')->whereIn('id', array_keys($ids))->pluck('name', 'id')->all() as $id => $name) {
            if (is_int($id) && is_string($name)) {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * The facts with each staff change carrying its members' names beside their ids.
     *
     * @param  array<mixed>  $facts
     * @param  array<int, string>  $names
     * @return array<mixed>
     */
    private static function namedStaff(array $facts, array $names): array
    {
        $changes = self::changes($facts);

        if ($changes === []) {
            return $facts;
        }

        $facts['staff_changed'] = array_map(fn (array $change): array => [
            ...$change,
            'from_name' => $names[$change['from']] ?? null,
            'to_name' => $names[$change['to']] ?? null,
        ], $changes);

        return $facts;
    }

    /**
     * A row's staff changes as the trail wrote them, and none where it wrote none or wrote
     * something else under the key.
     *
     * @param  array<mixed>  $facts
     * @return list<array{segment: int, from: int, to: int}>
     */
    private static function changes(array $facts): array
    {
        $changes = [];
        $listed = $facts['staff_changed'] ?? [];

        foreach (is_array($listed) ? $listed : [] as $change) {
            if (is_array($change) && is_int($change['segment'] ?? null) && is_int($change['from'] ?? null) && is_int($change['to'] ?? null)) {
                $changes[] = ['segment' => $change['segment'], 'from' => $change['from'], 'to' => $change['to']];
            }
        }

        return $changes;
    }

    private static function actor(string $value): ?Actor
    {
        try {
            return Actor::of($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The actor's kind, user, customer, system, job or support, and the raw value where it is none
     * of the five, which the client then renders verbatim.
     */
    private static function kind(string $actor): string
    {
        return match (true) {
            $actor === Actor::CUSTOMER, $actor === Actor::SYSTEM => $actor,
            str_starts_with($actor, 'user:') => 'user',
            str_starts_with($actor, 'job:') => 'job',
            str_starts_with($actor, 'support:') => 'support',
            default => $actor,
        };
    }
}
