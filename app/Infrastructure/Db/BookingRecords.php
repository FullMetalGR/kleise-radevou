<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\BookingFilter;
use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingRecord;
use App\Application\Dto\BookingSnapshot;
use App\Application\Dto\CalendarWindow;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Http\BookingPayload;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use stdClass;

/**
 * The bookings list and one booking, as the admin reads them: every filter a server parameter,
 * ANDed, a fixed page, the count of every matching row, and no sorting but the server's own
 * order, latest start first. A booking's start is its earliest item's.
 *
 * Staff and service match a booking when any of its items does, so a chain whose second segment
 * alone is the filtered service is listed. An address matches as citext compares it, which is
 * how a customer's page lists that customer's bookings in this same order. Search is a
 * case-insensitive substring of the customer columns the filter names, its wildcards escaped;
 * the filter names only the ones the viewer is shown, so a search never confirms a withheld
 * value.
 *
 * Two statements per page beyond the count: the page's bookings, then every item of those
 * bookings, so a page costs the same however long its chains are.
 *
 * Beside the list, Home's reads: the next seven days (perDay), one statement counting the
 * bookings still ahead by the date their first start falls on in the tenant's zone, and the two
 * feeds, Next up (nextUp) and Awaiting approval (awaiting).
 */
final readonly class BookingRecords
{
    private const string COLUMNS = 'booking.id, booking.uuid, booking.status, booking.hold_class, booking.hold_expires_at::text as hold_expires_at,'
        .' booking.customer_name, booking.customer_email, booking.customer_phone, booking.total_minor, booking.currency,'
        .' booking.payment_mode, booking.requires_approval, booking.on_approval_timeout, booking.approved_at::text as approved_at,'
        .' booking.approved_by, booking.rejection_reason, booking.payment_ref, booking.created_at::text as created_at,'
        .' booking.updated_at::text as updated_at, booking.source, booking.channel, booking.language';

    /**
     * The customer columns a search may read, as the SQL spells each.
     */
    private const array SEARCHABLE = [
        'customer_name' => 'booking.customer_name',
        'customer_email' => 'booking.customer_email::text',
    ];

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array{records: list<BookingRecord>, total: int}
     */
    public function page(Tenant $tenant, BookingFilter $filter): array
    {
        $matching = $this->matching($tenant, $filter);
        $total = (clone $matching)->count();

        $rows = $matching
            ->selectRaw(self::columns())
            ->orderByDesc('first.starts_at')
            ->orderByDesc('booking.id')
            ->offset($filter->offset())
            ->limit(BookingFilter::PAGE_SIZE)
            ->get()
            ->all();

        return ['records' => $this->records($tenant, array_values($rows)), 'total' => $total];
    }

    /**
     * The calendar's window: every booking with an item whose staff- occupied range, buffers and
     * processing gap alike, overlaps the window, in every status, so the screen cuts by status
     * and service on the client over one answer. The item's end for the overlap is the later of
     * its block end and its processing end, since the row holds the gap end past the customer's
     * end and not past the buffer, and either may outlast the other. Who is the one server cut:
     * in every mode but All, the item that brings a booking in must sit on one of the window's
     * members' rows, and an empty list brings none. Every item of each booking comes back, in
     * chain order, so a chain whose other segment is on another member's row is drawn whole
     * under All staff and filtered by the grid, never by the read.
     *
     * @return list<BookingRecord>
     */
    public function window(Tenant $tenant, CalendarWindow $window): array
    {
        $items = $tenant->schema_name.'.booking_items';
        ['from' => $from, 'until' => $until] = $window->range($tenant->zone());

        $rows = $this->db->connection()->table($tenant->schema_name.'.bookings', 'booking')
            ->whereExists(function (Builder $item) use ($items, $from, $until, $window): void {
                $item->from($items)
                    ->whereColumn('booking_id', 'booking.id')
                    ->whereRaw('block_start_utc < ?::timestamptz', [SqlText::instant($until)])
                    ->whereRaw('greatest(block_end_utc, processing_ends_utc) > ?::timestamptz', [SqlText::instant($from)]);

                if ($window->narrows()) {
                    $item->whereRaw('staff_id = any(?::bigint[])', [SqlText::bigintArray($window->staff)]);
                }
            })
            ->selectRaw(self::columns())
            ->orderBy('booking.id')
            ->get()
            ->all();

        return $this->records($tenant, array_values($rows));
    }

    public function one(Tenant $tenant, string $uuid): ?BookingRecord
    {
        if (! SqlText::isUuid($uuid)) {
            return null;
        }

        $row = $this->db->connection()->table($tenant->schema_name.'.bookings', 'booking')
            ->where('booking.uuid', $uuid)
            ->selectRaw(self::columns())
            ->first();

        return $row === null ? null : ($this->records($tenant, [$row])[0] ?? null);
    }

    /**
     * The bookings the uuids given name, each by its uuid, for Home's Recent activity: a trail
     * row's booking line and status. A uuid naming no booking, or not a uuid, is absent from the
     * answer.
     *
     * @param  list<string>  $uuids
     * @return array<string, BookingRecord>
     */
    public function byUuids(Tenant $tenant, array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids, SqlText::isUuid(...))));

        if ($uuids === []) {
            return [];
        }

        $rows = $this->db->connection()->table($tenant->schema_name.'.bookings', 'booking')
            ->whereIn('booking.uuid', $uuids)
            ->selectRaw(self::columns())
            ->get()
            ->all();
        $records = [];

        foreach ($this->records($tenant, array_values($rows)) as $record) {
            $records[$record->snapshot->uuid] = $record;
        }

        return $records;
    }

    /**
     * The rail's pending count: the bookings awaiting approval whose hold has not expired by
     * the database's clock, which is the rule the approve and reject guards now apply, so
     * every request counted here is one the owner can still decide.
     */
    public function pendingCount(Tenant $tenant): int
    {
        return $this->liveRequests($tenant)->count();
    }

    /** The requests awaiting approval on a hold still live: the rows pendingCount counts and awaiting lists. */
    private function liveRequests(Tenant $tenant): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.bookings', 'booking')
            ->where('booking.status', 'awaiting_approval')
            ->whereRaw(SqlText::holdIsLive('booking.hold_expires_at'));
    }

    /**
     * Home's Next up: the bookings whose first start is at or after the instant given, in
     * confirmed, awaiting approval and awaiting payment, soonest first, up to the limit.
     *
     * @param  positive-int  $limit
     * @return list<BookingRecord>
     */
    public function nextUp(Tenant $tenant, DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->db->connection()->table($tenant->schema_name.'.bookings', 'booking')
            ->joinSub(SqlText::firstStarts($tenant->schema_name), 'first', 'first.booking_id', '=', 'booking.id')
            ->whereIn('booking.status', array_map(fn (BookingStatus $status): string => $status->value, self::AHEAD))
            ->whereRaw('first.starts_at >= ?::timestamptz', [SqlText::instant($now)])
            ->selectRaw(self::columns())
            ->orderBy('first.starts_at')
            ->orderBy('booking.id')
            ->limit($limit)
            ->get()
            ->all();

        return $this->records($tenant, array_values($rows));
    }

    /**
     * Home's Awaiting approval: the rows pendingCount counts, the requests still on a live
     * hold, the one whose hold ends first first, up to the limit.
     *
     * @param  positive-int  $limit
     * @return list<BookingRecord>
     */
    public function awaiting(Tenant $tenant, int $limit): array
    {
        $rows = $this->liveRequests($tenant)
            ->selectRaw(self::columns())
            ->orderBy('booking.hold_expires_at')
            ->orderBy('booking.id')
            ->limit($limit)
            ->get()
            ->all();

        return $this->records($tenant, array_values($rows));
    }

    /**
     * The bookings still ahead of the business: the statuses Next up lists and the next seven days
     * count.
     */
    public const array AHEAD = [BookingStatus::Confirmed, BookingStatus::AwaitingApproval, BookingStatus::AwaitingPayment];

    /**
     * Home's next seven days: from the tenant's date given, two counts per day for the days asked,
     * of the bookings by first start in confirmed, awaiting approval and awaiting payment, grouped
     * by the date the first start falls on in the tenant's zone: `awaiting`, the requests awaiting
     * approval, and `confirmed`, the rest, an approved booking awaiting its payment counted with
     * the confirmed since no decision is owed on it. A day no booking starts on reads nought
     * twice, so every day is answered.
     *
     * @param  string  $from  the first day, Y-m-d in the tenant's zone
     * @param  positive-int  $days
     * @return list<array{date: string, confirmed: int, awaiting: int}>
     */
    public function perDay(Tenant $tenant, string $from, int $days): array
    {
        $zone = $tenant->zone();
        $utc = new DateTimeZone('UTC');
        $dates = [];

        for ($day = 0; $day < $days; $day++) {
            $dates[] = (new DateTimeImmutable($from, $utc))->add(new DateInterval(sprintf('P%dD', $day)))->format('Y-m-d');
        }

        $until = (new DateTimeImmutable($from, $utc))->add(new DateInterval(sprintf('P%dD', $days)))->format('Y-m-d');
        $shape = RowShape::of('bookings', $tenant);
        $rows = $this->db->connection()->select(sprintf(
            "select to_char(first.starts_at at time zone ?, 'YYYY-MM-DD') as day,"
            .' count(*) filter (where booking.status <> %3$s) as confirmed, count(*) filter (where booking.status = %3$s) as awaiting'
            .' from %1$s where booking.status in (%2$s) and first.starts_at >= ?::timestamptz and first.starts_at < ?::timestamptz'
            .' group by 1',
            SqlText::bookingsByFirstStart($tenant->schema_name),
            implode(', ', array_map(SqlText::literal(...), self::AHEAD)),
            SqlText::literal(BookingStatus::AwaitingApproval),
        ), [
            $zone->getName(),
            SqlText::instant(new DateTimeImmutable($from.' 00:00', $zone)),
            SqlText::instant(new DateTimeImmutable($until.' 00:00', $zone)),
        ]);

        $counts = array_fill_keys($dates, ['confirmed' => 0, 'awaiting' => 0]);

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $counts[$shape->string($row->day)] = ['confirmed' => $shape->int($row->confirmed), 'awaiting' => $shape->int($row->awaiting)];
        }

        return array_map(fn (string $date): array => ['date' => $date, ...($counts[$date] ?? ['confirmed' => 0, 'awaiting' => 0])], $dates);
    }

    /**
     * The columns, and the database's verdict on the hold beside them: the comparison every
     * other reader of a window makes, spelled once in SqlText.
     *
     * @return literal-string
     */
    private static function columns(): string
    {
        return self::COLUMNS.', '.SqlText::holdIsLive('booking.hold_expires_at').' as hold_is_live';
    }

    private function matching(Tenant $tenant, BookingFilter $filter): Builder
    {
        $items = $tenant->schema_name.'.booking_items';
        $range = $filter->range($tenant->zone());
        $query = $this->db->connection()->table($tenant->schema_name.'.bookings', 'booking')
            ->joinSub(SqlText::firstStarts($tenant->schema_name), 'first', 'first.booking_id', '=', 'booking.id');

        if ($range['from'] !== null) {
            $query->whereRaw('first.starts_at >= ?::timestamptz', [SqlText::instant($range['from'])]);
        }

        if ($range['until'] !== null) {
            $query->whereRaw('first.starts_at < ?::timestamptz', [SqlText::instant($range['until'])]);
        }

        if ($filter->status !== null) {
            $query->where('booking.status', $filter->status->value);
        }

        if ($filter->email !== null) {
            $query->whereRaw('booking.customer_email = ?::citext', [$filter->email]);
        }

        foreach (['staff_id' => $filter->staff, 'service_id' => $filter->service] as $column => $id) {
            if ($id !== null) {
                $query->whereExists(fn (Builder $item) => $item->from($items)
                    ->whereColumn('booking_id', 'booking.id')
                    ->where($column, $id));
            }
        }

        if ($filter->search !== null) {
            $like = '%'.addcslashes($filter->search, '\\%_').'%';
            $query->where(function (Builder $any) use ($filter, $like): void {
                $any->whereRaw('false');

                foreach ($filter->searchIn as $column) {
                    $any->orWhereRaw((self::SEARCHABLE[$column] ?? throw new LogicException(sprintf('The bookings search cannot read "%s".', $column))).' ilike ?', [$like]);
                }
            });
        }

        return $query;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<BookingRecord>
     */
    private function records(Tenant $tenant, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $items = $this->db->connection()
            ->table($tenant->schema_name.'.booking_items', 'item')
            ->join($tenant->schema_name.'.services as service', 'service.id', '=', 'item.service_id')
            ->join($tenant->schema_name.'.staff as member', 'member.id', '=', 'item.staff_id')
            ->whereIn('item.booking_id', array_map(fn (stdClass $row): mixed => $row->id, $rows))
            ->orderBy('item.booking_id')
            ->orderBy('item.sort')
            ->selectRaw('item.booking_id, item.sort, item.service_id, service.name as service_name, item.staff_id, member.name as staff_name,'
                .' member.is_implicit, item.start_utc::text as start_utc, item.end_utc::text as end_utc, item.price_minor,'
                .' item.block_start_utc::text as block_start_utc, item.block_end_utc::text as block_end_utc,'
                .' item.processing_ends_utc::text as processing_ends_utc, item.created_at::text as created_at, item.updated_at::text as updated_at')
            ->get();

        $shape = RowShape::of('bookings', $tenant);
        $byBooking = [];

        foreach ($items as $item) {
            $byBooking[$shape->int($item->booking_id)][] = $item;
        }

        return array_map(fn (stdClass $row): BookingRecord => $this->record($tenant, $row, $byBooking[$shape->int($row->id)] ?? []), $rows);
    }

    /**
     * @param  list<stdClass>  $items
     */
    private function record(Tenant $tenant, stdClass $row, array $items): BookingRecord
    {
        $shape = RowShape::of('bookings', $tenant);
        $currency = Currency::of($shape->string($row->currency));
        $snapshots = [];
        $operations = [];

        foreach ($items as $item) {
            $snapshots[] = new BookingItemSnapshot(
                $shape->int($item->sort),
                $shape->int($item->service_id),
                $shape->string($item->service_name),
                $shape->int($item->staff_id),
                $shape->string($item->staff_name),
                $item->is_implicit === true,
                SqlText::readInstant($shape->string($item->start_utc)),
                SqlText::readInstant($shape->string($item->end_utc)),
                new Money($shape->int($item->price_minor), $currency),
            );
            $operations[] = [
                'block_start_utc' => self::wire($item->block_start_utc),
                'block_end_utc' => self::wire($item->block_end_utc),
                'processing_ends_utc' => self::wire($item->processing_ends_utc),
                'created_at' => self::wire($item->created_at),
                'updated_at' => self::wire($item->updated_at),
            ];
        }

        $holdExpires = $row->hold_expires_at === null ? null : SqlText::readInstant($shape->string($row->hold_expires_at));

        return new BookingRecord(
            new BookingSnapshot(
                $shape->string($row->uuid),
                BookingStatus::from($shape->string($row->status)),
                $row->hold_class === null ? null : HoldClass::from($shape->string($row->hold_class)),
                $holdExpires,
                CustomerColumns::read($shape, $row->customer_name, $row->customer_email, $row->customer_phone),
                new Money($shape->int($row->total_minor), $currency),
                $snapshots,
                $shape->string($row->language),
            ),
            [
                'payment_mode' => $shape->string($row->payment_mode),
                'requires_approval' => $row->requires_approval === true,
                'on_approval_timeout' => $shape->string($row->on_approval_timeout),
                'approved_at' => self::wire($row->approved_at),
                'approved_by' => $row->approved_by === null ? null : $shape->int($row->approved_by),
                'rejection_reason' => $row->rejection_reason === null ? null : $shape->string($row->rejection_reason),
                'source' => $shape->string($row->source),
                'channel' => $row->channel === null ? null : $shape->string($row->channel),
                'payment_ref' => $row->payment_ref === null ? null : $shape->string($row->payment_ref),
                'created_at' => self::wire($row->created_at),
                'updated_at' => self::wire($row->updated_at),
            ],
            $operations,
            $row->hold_is_live === true,
        );
    }

    /**
     * A nullable instant column in the API's spelling, RFC 3339 in UTC.
     */
    private static function wire(mixed $text): ?string
    {
        return is_string($text) ? BookingPayload::instant(SqlText::readInstant($text)) : null;
    }
}
