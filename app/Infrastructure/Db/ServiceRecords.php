<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Http\ServicePayload;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use LogicException;
use stdClass;

/**
 * The catalog as its owner reads and writes it: every service whatever its status, because the
 * list is always fetched whole and the inactive toggle is a view filter; the roster the staff
 * checklist ticks, each member with the services they perform and their weekly windows; and
 * the writes. CatalogReader is the customer's read and stays the customer's: it answers active
 * rows through the whitelist, and this class answers the owner every column
 * ServicePayload::ADMIN names.
 *
 * The delete guard is here because it is a fact of the rows: a service any booking item names,
 * in any status, is refused with ServiceReferenced, and the fact is the foreign key on
 * booking_items.service_id, migration 000012, not a check of this class's own. The one delete
 * statement is refused by the key, or waits behind an item a hold has inserted and not yet
 * committed and is refused once it commits; a check before the delete would be a second
 * statement with a gap a hold could commit in. The staff links are replaced whole per member
 * from this side, so a save of one service never touches the services a member performs that
 * it did not name.
 *
 * Nothing here opens a transaction of its own but the insert with its implicit link, the
 * two-statement writes and the delete, and none of them enters the transaction runner: a
 * catalog write contends with no booking, and the delete contends with a hold on the terms
 * above.
 */
final readonly class ServiceRecords
{
    public function __construct(private DatabaseManager $db) {}

    /**
     * Every service, in the catalog's order, as the wire spells it.
     *
     * @return list<array<string, mixed>>
     */
    public function all(Tenant $tenant): array
    {
        return array_values(array_map(self::row(...), $this->services($tenant)->orderBy('sort')->orderBy('id')->get()->all()));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function one(Tenant $tenant, int $id): ?array
    {
        $row = $this->services($tenant)->where('id', $id)->first();

        return $row === null ? null : self::row($row);
    }

    /**
     * The distinct categories the catalogue's rows carry, in the order the booking page heads
     * them: each where its first row stands in the catalog's order. The service form suggests
     * them, so a second row lands under the first one's heading as spelled.
     *
     * @return list<string>
     */
    public function categories(Tenant $tenant): array
    {
        return array_values(array_map(self::string(...), $this->db->connection()->table($tenant->schema_name.'.services')
            ->whereNotNull('category')
            ->groupBy('category')
            ->orderByRaw('min(sort), min(id)')
            ->pluck('category')
            ->all()));
    }

    /**
     * Inserts a service after the last one in the catalog's order and answers its id. Where the
     * implicit member is the only staff row, the service is linked to it in the same
     * transaction: a service is offered online from its first day, and on a floor tenant's
     * roster nobody else could perform it. A roster with a second row keeps the checklist as
     * the only writer of links, so nothing is linked there.
     *
     * @param  array<string, mixed>  $columns
     */
    public function insert(Tenant $tenant, array $columns): int
    {
        $connection = $this->db->connection();
        $schema = $tenant->schema_name;

        return $connection->transaction(function () use ($connection, $schema, $columns): int {
            $sort = $connection->table($schema.'.services')->max('sort');

            $id = $connection->table($schema.'.services')->insertGetId([
                ...$columns,
                'sort' => (is_int($sort) ? $sort : 0) + 1,
            ]);

            $roster = $connection->table($schema.'.staff')->orderBy('id')->limit(2)->get(['id', 'is_implicit'])->all();
            $only = $roster[0] ?? null;

            if (count($roster) === 1 && $only !== null && $only->is_implicit === true) {
                $connection->table($schema.'.service_staff')->insert(['service_id' => $id, 'staff_id' => self::int($only->id)]);
            }

            return $id;
        });
    }

    /**
     * Writes the columns given onto the row and answers whether the row existed. An empty
     * patch touches nothing but still answers whether there was a row to touch.
     *
     * @param  array<string, mixed>  $columns
     */
    public function update(Tenant $tenant, int $id, array $columns): bool
    {
        $services = $this->db->connection()->table($tenant->schema_name.'.services')->where('id', $id);

        if ($columns === []) {
            return $services->exists();
        }

        return $services->update([...$columns, 'updated_at' => $this->db->connection()->raw('now()')]) === 1;
    }

    /**
     * Deletes the row and answers whether it existed. Refuses, with ServiceReferenced, a
     * service any booking item names, whatever the booking's status: the foreign key answers
     * that at the statement, so no order of this delete and a hold's insert can leave an item
     * naming a service that is gone. The statement runs in a transaction of its own so the
     * refusal is rolled back to where it was raised and aborts nothing around it: under a
     * wrapping transaction that is a savepoint, and on its own it is the statement's.
     *
     * @throws ServiceReferenced
     */
    public function delete(Tenant $tenant, int $id): bool
    {
        try {
            return $this->db->connection()->transaction(
                fn (): bool => $this->db->connection()->table($tenant->schema_name.'.services')->where('id', $id)->delete() === 1,
            );
        } catch (QueryException $exception) {
            if (SqlState::codeOf($exception) === ServiceReferenced::SQLSTATE) {
                throw new ServiceReferenced($id, $exception);
            }

            throw $exception;
        }
    }

    /**
     * Every staff row, the implicit member included, with the services each performs and their
     * usual week, the windows of their pattern, in the roster's order. The section that renders
     * this decides whether it is shown at all; the rows are the rows.
     *
     * @return list<array{id: int, name: string, active: bool, service_ids: list<int>, windows: list<array{weekday: int, start: string, end: string}>}>
     */
    public function roster(Tenant $tenant): array
    {
        $links = [];

        foreach ($this->db->connection()->table($tenant->schema_name.'.service_staff')->orderBy('service_id')->get(['staff_id', 'service_id']) as $link) {
            $links[self::int($link->staff_id)][] = self::int($link->service_id);
        }

        $patterns = WeeklyPattern::stored($this->db->connection(), $tenant->schema_name.'.');

        $roster = [];

        foreach ($this->db->connection()->table($tenant->schema_name.'.staff')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'status']) as $member) {
            $id = self::int($member->id);
            $roster[] = [
                'id' => $id,
                'name' => self::string($member->name),
                'active' => $member->status === 'active',
                'service_ids' => $links[$id] ?? [],
                'windows' => $patterns[$id]['windows'] ?? [],
            ];
        }

        return $roster;
    }

    /**
     * Replaces the services one member performs with the list given, and answers whether the
     * member exists. An id that names no service is the caller's to refuse before this; the
     * foreign key would refuse it here as a query error.
     *
     * @param  list<int>  $serviceIds
     */
    public function replaceMemberServices(Tenant $tenant, int $staffId, array $serviceIds): bool
    {
        if (! $this->db->connection()->table($tenant->schema_name.'.staff')->where('id', $staffId)->exists()) {
            return false;
        }

        $this->db->connection()->transaction(function () use ($tenant, $staffId, $serviceIds): void {
            $this->db->connection()->table($tenant->schema_name.'.service_staff')->where('staff_id', $staffId)->delete();

            if ($serviceIds !== []) {
                $this->db->connection()->table($tenant->schema_name.'.service_staff')->insert(array_map(
                    fn (int $serviceId): array => ['service_id' => $serviceId, 'staff_id' => $staffId],
                    array_values(array_unique($serviceIds)),
                ));
            }
        });

        return true;
    }

    /**
     * Whether every id names a service of this tenant.
     *
     * @param  list<int>  $serviceIds
     */
    public function allExist(Tenant $tenant, array $serviceIds): bool
    {
        $ids = array_values(array_unique($serviceIds));

        return $ids === [] || $this->db->connection()->table($tenant->schema_name.'.services')->whereIn('id', $ids)->count() === count($ids);
    }

    private function services(Tenant $tenant): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.services')->select(ServicePayload::ADMIN);
    }

    /**
     * A services row as the admin's wire spells it: every column ServicePayload::ADMIN names,
     * the counts as integers whatever width the driver read them at.
     *
     * @return array<string, mixed>
     */
    private static function row(stdClass $row): array
    {
        $out = [];

        foreach (ServicePayload::ADMIN as $column) {
            $value = $row->{$column} ?? null;
            $out[$column] = is_string($value) && in_array($column, ServicePayload::ADMIN_COUNTS, true) ? (int) $value : $value;
        }

        return $out;
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException('A catalog column read as something other than the integer the schema declares.');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('A catalog column read as something other than the text the schema declares.');
    }
}
