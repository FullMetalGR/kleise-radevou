<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\RowShape;
use App\Infrastructure\Db\SqlText;
use App\Infrastructure\Db\TextArray;
use App\Infrastructure\Db\TransactionRunner;
use App\Tenancy\Tenant;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use stdClass;

/**
 * The admin's export of one customer's data: the file the business, the controller of its
 * customers' data, hands a customer who asks what it holds about them.
 *
 * A customer is the bookings of one address, compared as citext compares bookings.customer_email,
 * as the page's route matches it (CustomerRecords::one); an erased booking's address is null and
 * belongs to nobody. In one transaction, the transaction runner's: one statement reads every
 * booking of the address in any status, whole and unpaged, each with its items and its intake
 * rows aggregated beside it, so the document is one snapshot of the tables as they stand; the
 * document is built and encoded; then one trail row per booking it holds, action
 * customer_exported, the principal's actor, an empty payload. A document that cannot be encoded
 * writes no trail row, and a trail that cannot be written sends no document: the caller has the
 * file only once the commit has landed, and nothing is kept on the server.
 *
 * The document, JSON in UTF-8: the business's name, the instant of the export and the tenant's
 * zone, every instant below spelled in that zone with its offset; the customer as the bookings
 * know them, every name, address spelling and phone seen, each once, the most recent first, as the
 * customer's page lists them; and every booking, the earliest first start first, with its public
 * id, its status, when it was made, the customer as that booking recorded them, its items in chain
 * order with their start and end, the service and the staff member by name as those rows stand now
 * (an item records their ids, never their names) and the item's price, the total and the currency,
 * the payment mode, the source and the channel, the approval facts (whether it needed an approval,
 * when it was approved, the reason it was rejected) and every booking_meta key with its value.
 * Beside the bookings, the address's profile, or null where none is kept, and its notes, the
 * oldest first, each author by the name they wrote under.
 *
 * Never in it: a credential, a token, a hash, a sealed value or an internal id. So the serial ids
 * of the booking, its items, its services and its staff, the manage token's hash and sealed copy,
 * the hold's class and expiry, the provider's payment reference and the approver's user id are not
 * read at all, and neither are an item's block range, its processing end and its blocking flag,
 * the tenant's arrangement and not the customer's. The ids the trail needs are read beside the
 * document and never enter it.
 *
 * Nothing of a row leaves this class but the document; a misshapen row is refused naming the
 * tenant and never a value. Every table is named by the tenant's schema; the trail's writer names
 * its tables as the runner's search path finds them.
 */
final readonly class CustomerExport
{
    /** How an instant is spelled in the document: RFC 3339 on the tenant's clock, its offset written out. */
    public const string INSTANT = DateTimeInterface::RFC3339;

    private const int JSON = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private TransactionRunner $runner,
        private Clock $clock,
    ) {}

    /**
     * The customer's document and the name it is downloaded under, customer-{slug}-{date}.json,
     * the date the tenant's today on the Clock port, the export's instant read once for both.
     *
     * @return array{file: string, json: string}
     *
     * @throws CustomerUnknown where no booking of the tenant carries the address
     */
    public function document(Tenant $tenant, string $email, Actor $actor): array
    {
        $zone = $tenant->zone();
        $now = $this->clock->now()->setTimezone($zone);

        $json = $this->runner->run($tenant, function (Connection $db) use ($tenant, $email, $actor, $zone, $now): string {
            $rows = $db->select(self::statement($tenant->schema_name), [$email]);

            if ($rows === []) {
                throw new CustomerUnknown($tenant->slug);
            }

            $shape = RowShape::of('customer export', $tenant);
            $bookings = [];
            $trail = [];

            foreach ($rows as $row) {
                $row = $shape->row($row);
                $uuid = $shape->string($row->uuid);
                $bookings[] = self::booking($row, $shape, $zone);
                $trail[] = [$shape->int($row->id), new AuditEntry($uuid, AuditAction::CustomerExported, $actor)];
            }

            $json = json_encode([
                'business' => $tenant->name,
                'exported_at' => $now->format(self::INSTANT),
                'timezone' => $zone->getName(),
                'customer' => self::customer($bookings),
                'profile' => self::profile($db, $tenant->schema_name, $email, $shape, $zone),
                'notes' => self::notes($db, $tenant->schema_name, $email, $shape, $zone),
                'bookings' => $bookings,
            ], self::JSON);

            AuditRow::writeAll($db, $trail);

            return $json;
        });

        return ['file' => sprintf('customer-%s-%s.json', $tenant->slug, $now->format('Y-m-d')), 'json' => $json];
    }

    /**
     * The one read: every booking of the address, its items and its intake rows aggregated beside
     * it, the earliest first start first, the later row breaking a tie. A booking's first start is
     * joined left, so a booking is read whatever its items.
     */
    private static function statement(string $schema): string
    {
        $item = 'json_build_object(\'service\', service.name, \'staff\', member.name, \'starts_at\', %1$s, \'ends_at\', %2$s, \'price_minor\', item.price_minor)';

        return sprintf(
            'select booking.id, booking.uuid::text as uuid, booking.status, %3$s as booked_at,'
            .' booking.customer_name as name, booking.customer_email::text as email, booking.customer_phone as phone,'
            .' booking.total_minor, booking.currency, booking.payment_mode, booking.source, booking.channel,'
            .' booking.requires_approval, %4$s as approved_at, booking.rejection_reason,'
            .' (select json_agg(%5$s order by item.sort) from %1$s.booking_items item'
            .' join %1$s.services service on service.id = item.service_id'
            .' join %1$s.staff member on member.id = item.staff_id'
            .' where item.booking_id = booking.id)::text as items,'
            .' (select json_object_agg(meta.key, meta.value order by meta.key) from %1$s.booking_meta meta where meta.booking_id = booking.id)::text as intake'
            .' from %1$s.bookings booking left join (%2$s) first on first.booking_id = booking.id'
            .' where booking.customer_email = ?::citext'
            .' order by first.starts_at nulls last, booking.id',
            $schema,
            SqlText::firstStarts($schema),
            SqlText::instantColumn('booking.created_at'),
            SqlText::instantColumn('booking.approved_at'),
            sprintf($item, SqlText::instantColumn('item.start_utc'), SqlText::instantColumn('item.end_utc')),
        );
    }

    /**
     * One booking as the document holds it.
     *
     * @return array<string, mixed>
     */
    private static function booking(stdClass $row, RowShape $shape, DateTimeZone $zone): array
    {
        return [
            'uuid' => $shape->string($row->uuid),
            'status' => $shape->string($row->status),
            'booked_at' => self::instant($shape->string($row->booked_at), $zone),
            'name' => $shape->string($row->name),
            'email' => $shape->string($row->email),
            'phone' => self::nullable($row->phone, $shape),
            'items' => self::items($row->items, $shape, $zone),
            'total_minor' => $shape->int($row->total_minor),
            'currency' => $shape->string($row->currency),
            'payment_mode' => $shape->string($row->payment_mode),
            'source' => $shape->string($row->source),
            'channel' => self::nullable($row->channel, $shape),
            'requires_approval' => $shape->bool($row->requires_approval),
            'approved_at' => $row->approved_at === null ? null : self::instant($shape->string($row->approved_at), $zone),
            'rejection_reason' => self::nullable($row->rejection_reason, $shape),
            'intake' => self::intake($row->intake, $shape),
        ];
    }

    /**
     * A booking's items as the statement aggregated them, in chain order, each instant on the
     * tenant's clock.
     *
     * @return list<array{service: string, staff: string, starts_at: string, ends_at: string, price_minor: int}>
     */
    private static function items(mixed $json, RowShape $shape, DateTimeZone $zone): array
    {
        if ($json === null) {
            return [];
        }

        $items = [];

        foreach (self::decoded($json, $shape) as $item) {
            if (! is_array($item)) {
                throw $shape->misshapen();
            }

            $items[] = [
                'service' => $shape->string($item['service'] ?? null),
                'staff' => $shape->string($item['staff'] ?? null),
                'starts_at' => self::instant($shape->string($item['starts_at'] ?? null), $zone),
                'ends_at' => self::instant($shape->string($item['ends_at'] ?? null), $zone),
                'price_minor' => $shape->int($item['price_minor'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * A booking's intake rows as one object of key and value, an empty object where it has none.
     */
    private static function intake(mixed $json, RowShape $shape): stdClass
    {
        $intake = new stdClass;

        foreach ($json === null ? [] : self::decoded($json, $shape) as $key => $value) {
            $intake->{(string) $key} = $shape->string($value);
        }

        return $intake;
    }

    /**
     * The address's profile: its tags in the order written, the birthday, the preferred language,
     * when online booking was blocked on the tenant's clock and why, or null where the address has
     * no profile row.
     *
     * @return array{tags: list<string>, birthday: string|null, language: string|null, blocked_at: string|null, blocked_reason: string|null}|null
     */
    private static function profile(Connection $db, string $schema, string $email, RowShape $shape, DateTimeZone $zone): ?array
    {
        $row = $db->selectOne(
            sprintf('select tags::text as tags, birthday::text as birthday, language, %s as blocked_at, blocked_reason from %s.customer_profiles where email = ?::citext', SqlText::instantColumn('blocked_at'), $schema),
            [$email],
        );

        if ($row === null) {
            return null;
        }

        $row = $shape->row($row);

        return [
            'tags' => TextArray::decode($shape->string($row->tags)),
            'birthday' => self::nullable($row->birthday, $shape),
            'language' => self::nullable($row->language, $shape),
            'blocked_at' => $row->blocked_at === null ? null : self::instant($shape->string($row->blocked_at), $zone),
            'blocked_reason' => self::nullable($row->blocked_reason, $shape),
        ];
    }

    /**
     * The address's notes, the oldest first: each body, the name its author wrote it under,
     * never the actor's id, and when, on the tenant's clock.
     *
     * @return list<array{body: string, author: string, written_at: string}>
     */
    private static function notes(Connection $db, string $schema, string $email, RowShape $shape, DateTimeZone $zone): array
    {
        $rows = $db->select(
            sprintf('select body, author_name, %s as created_at from %s.customer_notes note where note.email = ?::citext order by note.created_at, note.id', SqlText::instantColumn('created_at'), $schema),
            [$email],
        );

        return array_values(array_map(function (mixed $row) use ($shape, $zone): array {
            $row = $shape->row($row);

            return ['body' => $shape->string($row->body), 'author' => $shape->string($row->author_name), 'written_at' => self::instant($shape->string($row->created_at), $zone)];
        }, $rows));
    }

    /**
     * The customer as the bookings know them: every name, address spelling and phone seen, each
     * once, the most recent first, the bookings being in first-start order.
     *
     * @param  list<array<string, mixed>>  $bookings
     * @return array{names: list<string>, emails: list<string>, phones: list<string>}
     */
    private static function customer(array $bookings): array
    {
        $recent = array_reverse($bookings);

        return ['names' => self::seen($recent, 'name'), 'emails' => self::seen($recent, 'email'), 'phones' => self::seen($recent, 'phone')];
    }

    /**
     * Each distinct value of a column once, at its first place, a booking holding none adding
     * none.
     *
     * @param  list<array<string, mixed>>  $bookings
     * @return list<string>
     */
    private static function seen(array $bookings, string $column): array
    {
        return array_values(array_unique(array_filter(array_column($bookings, $column), is_string(...))));
    }

    /**
     * An instant the server wrote out, on the tenant's clock.
     */
    private static function instant(string $text, DateTimeZone $zone): string
    {
        return SqlText::readInstant($text)->setTimezone($zone)->format(self::INSTANT);
    }

    private static function nullable(mixed $value, RowShape $shape): ?string
    {
        return $value === null ? null : $shape->string($value);
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(mixed $json, RowShape $shape): array
    {
        $decoded = json_decode($shape->string($json), true);

        return is_array($decoded) ? $decoded : throw $shape->misshapen();
    }
}
