<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\FigureFilter;
use App\Application\Dto\FigureRange;
use App\Application\Dto\OverTime;
use App\Application\Dto\Report;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Enum\BookingChannel;
use App\Domain\Enum\BookingSource;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The Reports screen's reader: every figure a query over bookings and booking_items, computed when
 * asked, with no table, no cache and no stored row.
 *
 * Read beside BookingRecords and CustomerRecords and on the same footing: a booking is in the
 * range by its first start, the earliest of its items' starts, found by the join the Bookings
 * list makes, and compared with the range's two instants bound as UTC text with the offset. The
 * statuses whose bookings are revenue, confirmed and completed, are written into the statements'
 * text from the enum, as SqlText writes a case. Every row handed back is read through RowShape,
 * as theirs are.
 *
 * Three statements for the figures. The first groups the range's bookings by status, currency, source and
 * channel, and every booking-level figure is a sum over its rows: the count per status, the two
 * revenue lines, the no-show rate's counts, and the breakdowns by source and by channel, each
 * booking in exactly one row. The other two are the breakdowns through the items, by service and
 * by member, each the catalogue's table left-joined to the items in the range grouped by the row
 * and the booking's currency: a booking counted once per row however many of its items the row
 * holds, and the revenue the items' own prices, so a chain's total splits across its services and
 * its members exactly as it was summed. The catalogue's active rows are listed whether or not an
 * item names them, so an empty range reads zero rather than nothing; any other row is listed where
 * an item in the range names it.
 *
 * Reports' Staff and Service filters (FigureFilter, spelled by FilterSql) narrow every figure as
 * they narrow the tiles: a booking counts where one of its items matches, its revenue is those
 * items' own prices, and the breakdowns through the items read the matching items alone. Home's
 * week reads with none.
 *
 * Who cancelled a cancelled booking is read from its trail, the latest audit_log row of the two
 * actions that land on cancelled (Cancelled, Released): the customer's by the customer actor, the
 * business's by a user or a support operator, and unrecorded where no such row is left, a booking
 * written without one or a trail the retention sweep has deleted.
 *
 * Over time (overTime) is a fourth statement, Reports' alone: the counted bookings, confirmed and
 * completed, grouped by the tenant's date of their first start and summed into the range's
 * buckets (FigureRange::buckets), so the buckets sum to the Bookings and Revenue tiles; revenue in
 * another currency than the tenant's is left out of it, as the Revenue tile's figure leaves it.
 */
final readonly class ReportFigures
{
    /** Whether a booking is in the range, by its first start against the two bound instants. */
    private const string IN_RANGE = 'first.starts_at >= ?::timestamptz and first.starts_at < ?::timestamptz';

    /** The statuses whose bookings are revenue: every other counts toward none. */
    public const array EARNING = [BookingStatus::Confirmed, BookingStatus::Completed];

    /** A row's tally before any booking: no bookings and no revenue in any currency. */
    private const array NO_TALLY = ['bookings' => 0, 'revenue' => []];

    public function __construct(private DatabaseManager $db) {}

    /** The figures over Reports' range, read by its window alone. */
    public function read(Tenant $tenant, FigureRange $range, FigureFilter $filter = new FigureFilter): Report
    {
        $window = $range->window($tenant->zone());
        $bindings = [SqlText::instant($window['from']), SqlText::instant($window['until'])];
        $zero = Money::zero(Currency::of($tenant->currencyCode()));
        $shape = RowShape::of('reports', $tenant);

        $statuses = array_fill_keys(array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::cases()), 0);
        $revenue = [BookingStatus::Confirmed->value => [], BookingStatus::Completed->value => []];
        $sources = array_fill_keys(array_map(fn (BookingSource $source): string => $source->value, BookingSource::cases()), self::NO_TALLY);
        $channels = array_fill_keys([...array_map(fn (BookingChannel $channel): string => $channel->value, BookingChannel::cases()), ''], self::NO_TALLY);
        $cancelledBy = ['customer' => 0, 'business' => 0, 'unrecorded' => 0];

        foreach ($this->bookings($tenant, $shape, $bindings, $filter) as $row) {
            $status = BookingStatus::tryFrom($row['status']) ?? throw $shape->misshapen();
            $source = BookingSource::tryFrom($row['source']) ?? throw $shape->misshapen();
            $channel = $row['channel'] === null ? '' : (BookingChannel::tryFrom($row['channel']) ?? throw $shape->misshapen())->value;
            $earning = self::earning($status);

            $statuses[$status->value] += $row['bookings'];

            if ($row['canceller'] !== null) {
                $canceller = in_array($row['canceller'], ['customer', 'business'], true) ? $row['canceller'] : 'unrecorded';
                $cancelledBy[$canceller] += $row['bookings'];
            }

            if (isset($revenue[$status->value])) {
                $revenue[$status->value][$row['currency']] = ($revenue[$status->value][$row['currency']] ?? 0) + $row['minor'];
            }

            $sources[$source->value] = self::tallied($sources[$source->value], $row['bookings'], $earning ? $row['currency'] : null, $row['minor']);
            $channels[$channel] = self::tallied($channels[$channel], $row['bookings'], $earning ? $row['currency'] : null, $row['minor']);
        }

        $sourceRows = [];

        foreach ($sources as $source => $tally) {
            $sourceRows[] = ['source' => (string) $source, 'bookings' => $tally['bookings'], 'revenue' => self::lines($tally['revenue'], $zero)];
        }

        $channelRows = [];

        foreach ($channels as $channel => $tally) {
            $channelRows[] = ['channel' => $channel === '' ? null : (string) $channel, 'bookings' => $tally['bookings'], 'revenue' => self::lines($tally['revenue'], $zero)];
        }

        return new Report(
            $statuses,
            self::lines($revenue[BookingStatus::Confirmed->value], $zero),
            self::lines($revenue[BookingStatus::Completed->value], $zero),
            $statuses[BookingStatus::NoShow->value],
            $statuses[BookingStatus::Completed->value],
            $this->catalogue($tenant, $shape, 'services', 'service_id', 'false', $bindings, $zero, $filter),
            $this->catalogue($tenant, $shape, 'staff', 'staff_id', 'listed.is_implicit', $bindings, $zero, $filter),
            $sourceRows,
            $channelRows,
            $cancelledBy,
        );
    }

    /**
     * Over time: the range's buckets, each with the counted bookings whose first start falls on
     * one of its dates in the tenant's zone and their revenue in the tenant's currency, under the
     * filters.
     *
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     */
    public function overTime(Tenant $tenant, FigureRange $range, FigureFilter $filter, int $startOfWeek): OverTime
    {
        $window = $range->window($tenant->zone());
        $currency = Currency::of($tenant->currencyCode());
        $shape = RowShape::of('reports', $tenant);
        $buckets = $range->buckets($startOfWeek);

        $rows = $this->db->connection()->select(sprintf(
            "select to_char(first.starts_at at time zone ?, 'YYYY-MM-DD') as day, booking.currency::text as currency,"
            .' count(*) as bookings, coalesce(sum(%1$s), 0)::bigint as minor'
            .' from %2$s where %3$s and %4$s group by 1, 2',
            FilterSql::minor($filter),
            FilterSql::bookings($tenant->schema_name, $filter),
            self::IN_RANGE,
            self::earningCondition(),
        ), [$tenant->zone()->getName(), SqlText::instant($window['from']), SqlText::instant($window['until'])]);

        $bookings = array_fill(0, count($buckets['rows']), 0);
        $minors = array_fill(0, count($buckets['rows']), 0);

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $day = $shape->string($row->day);

            foreach ($buckets['rows'] as $index => $bucket) {
                if ($day >= $bucket['from'] && $day <= $bucket['to']) {
                    $bookings[$index] += $shape->int($row->bookings);
                    $minors[$index] += $shape->string($row->currency) === $currency->code ? $shape->int($row->minor) : 0;

                    break;
                }
            }
        }

        $lines = [];

        foreach ($buckets['rows'] as $index => $bucket) {
            $lines[] = [...$bucket, 'bookings' => $bookings[$index], 'revenue' => new Money($minors[$index], $currency)];
        }

        return new OverTime($buckets['unit'], $lines);
    }

    /**
     * Whether a booking's status is revenue, as the statements' text spells it.
     */
    private static function earningCondition(): string
    {
        return sprintf('booking.status in (%s)', implode(', ', array_map(SqlText::literal(...), self::EARNING)));
    }

    private static function earning(BookingStatus $status): bool
    {
        return in_array($status, self::EARNING, true);
    }

    /**
     * Who cancelled a booking, as the statement spells it: for a cancelled booking the latest
     * trail row of an action that lands on cancelled, customer for the customer actor, business
     * for a user or a support operator, unrecorded for anything else or no row; null for a
     * booking in any other status.
     */
    private static function canceller(Tenant $tenant): string
    {
        return sprintf(
            "case when booking.status = %1\$s then coalesce((select case when trail.actor = '%2\$s' then 'customer'"
            ." when trail.actor ~ '%3\$s' or trail.actor ~ '%4\$s' then 'business' else 'unrecorded' end"
            .' from %5$s.audit_log trail where trail.booking_id = booking.id and trail.action in (%6$s, %7$s)'
            ." order by trail.created_at desc, trail.id desc limit 1), 'unrecorded') end",
            SqlText::literal(BookingStatus::Cancelled),
            Actor::CUSTOMER,
            Actor::USER_SHAPE,
            Actor::SUPPORT_SHAPE,
            $tenant->schema_name,
            SqlText::literal(AuditAction::Cancelled),
            SqlText::literal(AuditAction::Released),
        );
    }

    /**
     * The range's bookings grouped by everything a booking-level figure reads.
     *
     * A cancelled booking's canceller is customer, business or unrecorded; any other booking's
     * is null.
     *
     * @param  list<string>  $bindings
     * @return list<array{status: string, currency: string, source: string, channel: string|null, canceller: string|null, bookings: int, minor: int}>
     */
    private function bookings(Tenant $tenant, RowShape $shape, array $bindings, FigureFilter $filter): array
    {
        $rows = $this->db->connection()->select(sprintf(
            'select booking.status, booking.currency::text as currency, booking.source, booking.channel, %1$s as canceller,'
            .' count(*) as bookings, sum(%2$s)::bigint as minor'
            .' from %3$s where %4$s'
            .' group by 1, 2, 3, 4, 5',
            self::canceller($tenant),
            FilterSql::minor($filter),
            FilterSql::bookings($tenant->schema_name, $filter),
            self::IN_RANGE,
        ), $bindings);

        return array_values(array_map(function (mixed $row) use ($shape): array {
            $row = $shape->row($row);

            return [
                'status' => $shape->string($row->status),
                'currency' => $shape->string($row->currency),
                'source' => $shape->string($row->source),
                'channel' => $row->channel === null ? null : $shape->string($row->channel),
                'canceller' => $row->canceller === null ? null : $shape->string($row->canceller),
                'bookings' => $shape->int($row->bookings),
                'minor' => $shape->int($row->minor),
            ];
        }, $rows));
    }

    /**
     * A breakdown through the items: the catalogue table's active rows and every row an item in
     * the range names, in the catalogue's order, each with the bookings counted once and the
     * revenue of its items in confirmed and completed bookings, per currency. The implicit member
     * is named as the business, the tenant's name.
     *
     * @param  'services'|'staff'  $table
     * @param  'service_id'|'staff_id'  $column  the item's column naming the table's row
     * @param  'false'|'listed.is_implicit'  $implicit  whether a row stands for the business itself
     * @param  list<string>  $bindings
     * @return list<array{id: int, name: string, bookings: int, revenue: non-empty-list<Money>}>
     */
    private function catalogue(Tenant $tenant, RowShape $shape, string $table, string $column, string $implicit, array $bindings, Money $zero, FigureFilter $filter): array
    {
        $rows = $this->db->connection()->select(sprintf(
            'select listed.id, listed.name, %1$s as implicit, figures.currency, figures.bookings, figures.minor, figures.earning'
            .' from %2$s.%3$s listed left join ('
            .'select item.%4$s as id, booking.currency::text as currency, count(distinct booking.id) as bookings,'
            .' coalesce(sum(item.price_minor) filter (where %5$s), 0)::bigint as minor, bool_or(%5$s) as earning'
            .' from %6$s join %2$s.booking_items item on item.booking_id = booking.id'
            .' where %7$s and %8$s'
            .' group by item.%4$s, booking.currency'
            .') figures on figures.id = listed.id'
            ." where listed.status = 'active' or figures.id is not null"
            .' order by listed.sort, listed.id, figures.currency',
            $implicit,
            $tenant->schema_name,
            $table,
            $column,
            self::earningCondition(),
            SqlText::bookingsByFirstStart($tenant->schema_name),
            self::IN_RANGE,
            FilterSql::item($filter),
        ), $bindings);

        $names = [];
        $tallies = [];

        foreach ($rows as $row) {
            $row = $shape->row($row);
            $id = $shape->int($row->id);
            $name = $shape->string($row->name);
            $names[$id] = $shape->bool($row->implicit) ? $tenant->name : $name;
            $tallies[$id] ??= self::NO_TALLY;

            if ($row->currency !== null) {
                $currency = $shape->string($row->currency);
                $bookings = $shape->int($row->bookings);
                $minor = $shape->int($row->minor);
                $tallies[$id] = self::tallied($tallies[$id], $bookings, $shape->bool($row->earning) ? $currency : null, $minor);
            }
        }

        $breakdown = [];

        foreach ($tallies as $id => $tally) {
            $breakdown[] = ['id' => $id, 'name' => $names[$id] ?? throw $shape->misshapen(), 'bookings' => $tally['bookings'], 'revenue' => self::lines($tally['revenue'], $zero)];
        }

        return $breakdown;
    }

    /**
     * A row's tally with bookings added, and an amount added to its revenue in the currency given,
     * where the bookings are revenue.
     *
     * @param  array{bookings: int, revenue: array<string, int>}  $tally
     * @return array{bookings: int, revenue: array<string, int>}
     */
    private static function tallied(array $tally, int $bookings, ?string $currency, int $minor): array
    {
        $tally['bookings'] += $bookings;

        if ($currency !== null) {
            $tally['revenue'][$currency] = ($tally['revenue'][$currency] ?? 0) + $minor;
        }

        return $tally;
    }

    /**
     * A revenue tally as its lines, one per currency in the order of the code, or zero in the
     * tenant's currency where no booking that is revenue carried one.
     *
     * @param  array<string, int>  $minors
     * @return non-empty-list<Money>
     */
    private static function lines(array $minors, Money $zero): array
    {
        if ($minors === []) {
            return [$zero];
        }

        ksort($minors, SORT_STRING);
        $lines = [];

        foreach ($minors as $code => $minor) {
            $lines[] = new Money($minor, Currency::of((string) $code));
        }

        return $lines;
    }
}
