<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\CalendarWindow;
use App\Application\Dto\FigureFilter;
use App\Application\Dto\FigureRange;
use App\Application\Dto\FirstInstant;
use App\Application\Dto\PeriodFigures;
use App\Application\Dto\RangeFigures;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\Occupancy;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * Home's figures over a range, read beside ReportFigures and on the same footing: a booking is in
 * a period by its first start, the earliest of its items' starts (SqlText::bookingsByFirstStart),
 * compared with two instants bound as UTC text with the offset; the counted statuses, confirmed
 * and completed, are written into the statements' text from the enum. Nothing is stored.
 *
 * ReportFigures is not called: its breakdowns count every status and its revenue is per currency,
 * where these count the confirmed and completed alone, and it reads one period where these read
 * two. The range and its period before are contiguous, so every statement reads both at once
 * over the one span from the period before's first instant to the range's last, each row told
 * apart by whether its first start is at or after the range's first instant.
 *
 * Eight statements whatever the range: the booking tiles, grouped by period and currency; the
 * counted items, each with its member, its start and its end and the period it falls in; the
 * customers, by period; Top services and Top staff, the range's five each; the members whose
 * hours are offered; the names of the members Occupancy by staff lists; and the hours through
 * StaffHours::of over both periods' dates (whose own statements, the patterns, the overrides and
 * the closures, are the rest).
 *
 * Occupancy: the filled minutes are the counted items' service time, and the offered minutes the
 * members' hours less closures plus every minute of a counted item outside its member's offered
 * time, which is filled and offered both, so occupancy never passes 100 percent. The range's
 * figures per member are Occupancy by staff: the members whose hours are offered and every member
 * with a counted item in the range, in the catalogue's order, the business's own row only where
 * it offers or holds a minute.
 *
 * Reports' Staff and Service filters (FigureFilter) narrow the six tiles: a booking counts where
 * one of its items matches, its revenue is those items' own prices rather than its total, its
 * filled minutes those items' alone, its customer a customer of the filter (whose first booking
 * ever is still read across every booking they hold), and the offered minutes are the member's,
 * or the members' who perform the service. The ids are written into the statements' text as
 * integers (FilterSql). Top services and Top staff, Home's alone, are never filtered, and
 * Reports, which shows neither, reads the tiles alone (tilesOnly) and never runs their two
 * statements.
 */
final readonly class RangeFigureRecords
{
    /** The statuses the tiles count as bookings and revenue. */
    private const array COUNTED = [BookingStatus::Confirmed, BookingStatus::Completed];

    /** The rows each Top table lists. */
    private const int TOP = 5;

    /** Whether a booking's first start is in the span both periods make. */
    private const string IN_SPAN = 'first.starts_at >= ?::timestamptz and first.starts_at < ?::timestamptz';

    /** Whether a booking's first start is in the range rather than the period before. */
    private const string IN_RANGE = 'first.starts_at >= ?::timestamptz';

    public function __construct(private DatabaseManager $db, private StaffHours $hours) {}

    /**
     * The tiles over the range and the period before, with the range's Top services and Top
     * staff: Home's figures.
     */
    public function read(Tenant $tenant, FigureRange $range, FigureFilter $filter = new FigureFilter): RangeFigures
    {
        return $this->figures($tenant, $range, $filter, true);
    }

    /**
     * The tiles alone, the two Top tables left empty and their statements never run: Reports',
     * which shows no Top table.
     */
    public function tilesOnly(Tenant $tenant, FigureRange $range, FigureFilter $filter): RangeFigures
    {
        return $this->figures($tenant, $range, $filter, false);
    }

    private function figures(Tenant $tenant, FigureRange $range, FigureFilter $filter, bool $top): RangeFigures
    {
        $before = $range->before();
        $zone = $tenant->zone();
        $from = SqlText::instant($before->window($zone)['from']);
        $start = SqlText::instant($range->window($zone)['from']);
        $until = SqlText::instant($range->window($zone)['until']);
        $currency = Currency::of($tenant->currencyCode());
        $shape = RowShape::of('home figures', $tenant);

        $tiles = $this->tiles($tenant, $shape, $from, $start, $until, $currency, $filter);
        $items = $this->items($tenant, $shape, $from, $start, $until, $filter);
        $customers = $this->customers($tenant, $shape, $from, $start, $until, $filter);
        $offered = $this->offered($tenant, $before->from, $range->to, $filter);
        $occupancy = [
            'now' => Occupancy::byMember($offered, $zone, $range->from, $range->to, $items['now']),
            'before' => Occupancy::byMember($offered, $zone, $before->from, $before->to, $items['before']),
        ];

        $period = fn (string $key, string $first, string $last): PeriodFigures => new PeriodFigures(
            $tiles[$key]['bookings'],
            new Money($tiles[$key]['revenue'], $currency),
            $tiles[$key]['elsewhere'],
            $tiles[$key]['cancelled'],
            $tiles[$key]['no_shows'],
            $customers[$key]['new'],
            $customers[$key]['customers'],
            array_sum(array_column($occupancy[$key], 'booked')),
            array_sum(array_column($occupancy[$key], 'available')),
        );

        return new RangeFigures(
            $range,
            $period('now', $range->from, $range->to),
            $period('before', $before->from, $before->to),
            $top ? array_map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'bookings' => $row['bookings'], 'revenue' => $row['revenue']], $this->top($tenant, $shape, 'services', 'service_id', $start, $until, $currency)) : [],
            $top ? $this->top($tenant, $shape, 'staff', 'staff_id', $start, $until, $currency) : [],
            $this->byStaff($tenant, $shape, $occupancy['now']),
        );
    }

    private static function counted(): string
    {
        return sprintf('booking.status in (%s)', implode(', ', array_map(SqlText::literal(...), self::COUNTED)));
    }

    /** The period a row's first start falls in, as the statements name it. */
    private static function period(): string
    {
        return sprintf("case when %s then 'now' else 'before' end", self::IN_RANGE);
    }

    /**
     * The booking tiles per period: the counted bookings, their totals in the tenant's currency
     * and in any other, the cancelled and the no-shows.
     *
     * @return array<'now'|'before', array{bookings: int, revenue: int, elsewhere: list<Money>, cancelled: int, no_shows: int}>
     */
    private function tiles(Tenant $tenant, RowShape $shape, string $from, string $start, string $until, Currency $currency, FigureFilter $filter): array
    {
        $source = FilterSql::bookings($tenant->schema_name, $filter);
        $minor = FilterSql::minor($filter);

        $rows = $this->db->connection()->select(sprintf(
            'select %1$s as period, booking.currency::text as currency,'
            .' count(*) filter (where %2$s) as bookings,'
            .' coalesce(sum(%7$s) filter (where %2$s), 0)::bigint as minor,'
            .' count(*) filter (where booking.status = %3$s) as cancelled,'
            .' count(*) filter (where booking.status = %4$s) as no_shows'
            .' from %5$s where %6$s'
            .' group by 1, 2 order by 2',
            self::period(),
            self::counted(),
            SqlText::literal(BookingStatus::Cancelled),
            SqlText::literal(BookingStatus::NoShow),
            $source,
            self::IN_SPAN,
            $minor,
        ), [$start, $from, $until]);

        $tiles = [];

        foreach (['now', 'before'] as $key) {
            $tiles[$key] = ['bookings' => 0, 'revenue' => 0, 'elsewhere' => [], 'cancelled' => 0, 'no_shows' => 0];
        }

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $key = $shape->string($row->period) === 'now' ? 'now' : 'before';
            $code = $shape->string($row->currency);
            $minor = $shape->int($row->minor);
            $tiles[$key]['bookings'] += $shape->int($row->bookings);
            $tiles[$key]['cancelled'] += $shape->int($row->cancelled);
            $tiles[$key]['no_shows'] += $shape->int($row->no_shows);

            if ($code === $currency->code) {
                $tiles[$key]['revenue'] += $minor;
            } elseif ($minor !== 0) {
                $tiles[$key]['elsewhere'][] = new Money($minor, Currency::of($code));
            }
        }

        return $tiles;
    }

    /**
     * The counted bookings' items per period, each its member and its start and end as Unix
     * seconds: service time, no buffer and no processing time.
     *
     * @return array{now: list<array{staff: int, start: int, end: int}>, before: list<array{staff: int, start: int, end: int}>}
     */
    private function items(Tenant $tenant, RowShape $shape, string $from, string $start, string $until, FigureFilter $filter): array
    {
        $rows = $this->db->connection()->select(sprintf(
            'select %1$s as period, item.staff_id as staff,'
            .' extract(epoch from item.start_utc)::bigint as starts, extract(epoch from item.end_utc)::bigint as ends'
            .' from %2$s join %3$s.booking_items item on item.booking_id = booking.id'
            .' where %4$s and %5$s and %6$s',
            self::period(),
            SqlText::bookingsByFirstStart($tenant->schema_name),
            $tenant->schema_name,
            self::IN_SPAN,
            self::counted(),
            FilterSql::item($filter),
        ), [$start, $from, $until]);

        $items = ['now' => [], 'before' => []];

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $items[$shape->string($row->period) === 'now' ? 'now' : 'before'][] = ['staff' => $shape->int($row->staff), 'start' => $shape->int($row->starts), 'end' => $shape->int($row->ends)];
        }

        return $items;
    }

    /**
     * Occupancy by staff's rows: every member of the range's figures in the catalogue's order, the
     * business's own named as the business and listed only where it offers or holds a minute.
     *
     * @param  array<int, array{booked: int, available: int}>  $members
     * @return list<array{id: int, name: string, booked: int, available: int}>
     */
    private function byStaff(Tenant $tenant, RowShape $shape, array $members): array
    {
        if ($members === []) {
            return [];
        }

        $rows = $this->db->connection()->table($tenant->schema_name.'.staff')
            ->whereIn('id', array_keys($members))->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'is_implicit'])->all();
        $listed = [];

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $id = $shape->int($row->id);
            $figures = $members[$id] ?? throw $shape->misshapen();
            $business = $shape->bool($row->is_implicit);

            if ($business && $figures['booked'] === 0 && $figures['available'] === 0) {
                continue;
            }

            $listed[] = ['id' => $id, 'name' => $business ? $tenant->name : $shape->string($row->name), ...$figures];
        }

        return $listed;
    }

    /**
     * The customers per period: the addresses with a counted booking in it, and those of them
     * whose first counted booking ever starts in it. An erased booking has no address and is
     * nobody's.
     *
     * @return array<'now'|'before', array{new: int, customers: int}>
     */
    private function customers(Tenant $tenant, RowShape $shape, string $from, string $start, string $until, FigureFilter $filter): array
    {
        $rows = $this->db->connection()->select(sprintf(
            'with counted as ('
            .'select booking.customer_email as email, first.starts_at,'
            .' exists (select 1 from %6$s.booking_items item where item.booking_id = booking.id and %7$s) as matches from %1$s'
            .' where %2$s and booking.customer_email is not null'
            .'), firsts as (select email, min(starts_at) as first_ever from counted group by email)'
            .' select %3$s as period, count(distinct first.email) as customers,'
            .' count(distinct first.email) filter (where firsts.first_ever >= case when %4$s then ?::timestamptz else ?::timestamptz end) as fresh'
            .' from counted first join firsts on firsts.email = first.email'
            .' where %5$s and first.matches group by 1',
            SqlText::bookingsByFirstStart($tenant->schema_name),
            self::counted(),
            self::period(),
            self::IN_RANGE,
            self::IN_SPAN,
            $tenant->schema_name,
            FilterSql::item($filter),
        ), [$start, $start, $start, $from, $from, $until]);

        $customers = ['now' => ['new' => 0, 'customers' => 0], 'before' => ['new' => 0, 'customers' => 0]];

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $customers[$shape->string($row->period) === 'now' ? 'now' : 'before'] = ['new' => $shape->int($row->fresh), 'customers' => $shape->int($row->customers)];
        }

        return $customers;
    }

    /**
     * The range's five rows of services or members by the counted bookings' items: the bookings
     * holding an item of the row, each once, and the items' prices in the tenant's currency; by
     * revenue, then bookings, then name, the id breaking a tie. The implicit member is named as
     * the business.
     *
     * @param  'services'|'staff'  $table
     * @param  'service_id'|'staff_id'  $column
     * @return list<array{id: int, name: string, business: bool, bookings: int, revenue: Money}>
     */
    private function top(Tenant $tenant, RowShape $shape, string $table, string $column, string $start, string $until, Currency $currency): array
    {
        $implicit = $table === 'staff' ? 'listed.is_implicit' : 'false';
        $rows = $this->db->connection()->select(sprintf(
            'select listed.id, case when %1$s then ? else listed.name end as name, %1$s as business,'
            .' count(distinct booking.id) as bookings,'
            .' coalesce(sum(item.price_minor) filter (where booking.currency = ?), 0)::bigint as minor'
            .' from %2$s join %3$s.booking_items item on item.booking_id = booking.id'
            .' join %3$s.%4$s listed on listed.id = item.%5$s'
            .' where first.starts_at >= ?::timestamptz and first.starts_at < ?::timestamptz and %6$s'
            .' group by listed.id'
            .' order by minor desc, bookings desc, 2 asc, listed.id asc limit %7$d',
            $implicit,
            SqlText::bookingsByFirstStart($tenant->schema_name),
            $tenant->schema_name,
            $table,
            $column,
            self::counted(),
            self::TOP,
        ), [$tenant->name, $currency->code, $start, $until]);

        return array_values(array_map(function (mixed $row) use ($shape, $currency): array {
            $row = $shape->row($row);

            return [
                'id' => $shape->int($row->id),
                'name' => $shape->string($row->name),
                'business' => $shape->bool($row->business),
                'bookings' => $shape->int($row->bookings),
                'revenue' => new Money($shape->int($row->minor), $currency),
            ];
        }, $rows));
    }

    /**
     * The hours of the members the filter offers over the dates from the first to the last, both
     * inclusive, through StaffHours::of, the seam the Calendar draws its hours by: the member
     * chosen, else the active members who perform the service chosen, else every active member.
     *
     * @return array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>>
     */
    private function offered(Tenant $tenant, string $first, string $last, FigureFilter $filter): array
    {
        $members = $this->db->connection()->table($tenant->schema_name.'.staff')->where('status', 'active')->orderBy('id');

        if ($filter->staffId !== null) {
            $members->where('id', $filter->staffId);
        } elseif ($filter->serviceId !== null) {
            $members->whereIn('id', $this->db->connection()->table($tenant->schema_name.'.service_staff')->where('service_id', $filter->serviceId)->select('staff_id'));
        }

        $ids = array_values(array_map(fn (mixed $id): int => BlackoutRecords::int($id), $members->pluck('id')->all()));
        // A custom range from the calendar's first day has a period before in the year 0, which
        // the database cannot spell and which holds no hours: the read starts at the first day.
        $first = max($first, FirstInstant::at()->format('Y-m-d'));

        return $this->hours->of($tenant, $ids, new CalendarWindow($first, CalendarDay::plusDays($last, 1)));
    }
}
