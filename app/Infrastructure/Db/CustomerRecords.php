<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Clock;
use App\Application\Dto\CustomerFilter;
use App\Application\Dto\CustomerRecord;
use App\Domain\Enum\BookingStatus;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * The Customers screen's reader: a customer is no row of its own but the bookings of one address,
 * grouped by bookings.customer_email, whose citext type makes the grouping case-insensitive, so
 * Casey@Example.com and casey@example.com are one customer. Read beside BookingRecords and on the
 * same footing: a booking's visit is its first start, the earliest of its items' starts, found by
 * the join the Bookings list makes, a subquery over booking_items grouped by booking; and the
 * bookings counted are every one in any status.
 *
 * One grouped statement answers a page. Recency is one ordering, spelled once: a booking is more
 * recent than another when its first start is later, the later row breaking a tie. Each aggregate
 * that answers "most recent first" is taken in that order: the address answered is the one the
 * most recent booking stored, which is what a customer's page is linked by; the names and the
 * phones seen are aggregated in that order, phones that are null left out, and read back here
 * each distinct value once at its first, most recent, place. The first visit is the least first
 * start; the last visit is the latest first start at or before now in confirmed or completed,
 * null where none is; and the list is ordered by the latest first start in any status, the
 * latest first, the address breaking a tie.
 *
 * The page's tiles ride on the same statement, each an aggregate over the address's bookings
 * measured against now, the clock's instant bound into the statement: upcoming counts those
 * confirmed, awaiting approval or awaiting payment whose first start is ahead of now; completed,
 * no-shows and cancelled count their status alone; and the last visit is the latest first start
 * already passed in confirmed or completed, null where the address has none.
 *
 * Search is the Bookings search: a case-insensitive substring of the customer columns the filter
 * names, its wildcards escaped, an address answering when any of its bookings matches, so a name
 * it booked under once still finds it, and the whole address returned, every booking counted.
 * The filter names only the columns the viewer is shown, so a search never confirms a withheld
 * address.
 *
 * Two statements per page, as the Bookings list's: the count of every matching address, then the
 * page. Both read only the bookings whose address stands: an erased booking belongs to nobody the
 * screen can list.
 */
final readonly class CustomerRecords
{
    /**
     * The customer columns a search may read, as the SQL spells each over a booking.
     */
    private const array SEARCHABLE = [
        'customer_name' => 'booking.customer_name',
        'customer_email' => 'booking.customer_email::text',
    ];

    /**
     * The bookings a customer is made of: those whose address stands. An erased booking's is
     * null (tenant migration 000028), so an erased customer leaves the list, the count and every
     * page, and no row is ever linked by a null.
     */
    private const string NAMED = 'booking.customer_email is not null';

    /** The one order of recency every "most recent first" aggregate is taken in. */
    private const string RECENT_FIRST = 'order by first.starts_at desc, booking.id desc';

    /** The statuses a booking ahead of now counts as upcoming in, the ones still ahead. */
    private const array UPCOMING = BookingRecords::AHEAD;

    /** The statuses a booking already passed counts as a visit in: the ones that earn. */
    private const array VISITED = ReportFigures::EARNING;

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    /**
     * @return array{customers: list<CustomerRecord>, total: int}
     */
    public function page(Tenant $tenant, CustomerFilter $filter): array
    {
        [$having, $searched] = self::search($filter);
        $counted = $this->db->connection()->selectOne(
            sprintf('select count(*) as total from (select 1 from %s where %s group by booking.customer_email having %s) customer', SqlText::bookingsByFirstStart($tenant->schema_name), self::NAMED, $having),
            $searched,
        );
        $shape = RowShape::of('customers', $tenant);
        $total = $shape->int($shape->row($counted)->total);

        return [
            'customers' => $this->customers($tenant, 'true', [], $having, $searched, CustomerFilter::PAGE_SIZE, $filter->offset()),
            'total' => $total,
        ];
    }

    /**
     * Every address the filter's search matches, in the list's order and not paged: the Customers
     * list's Download CSV, what the screen shows under its search.
     *
     * @return list<CustomerRecord>
     */
    public function every(Tenant $tenant, CustomerFilter $filter): array
    {
        [$having, $searched] = self::search($filter);

        return $this->customers($tenant, 'true', [], $having, $searched, null, 0);
    }

    /**
     * The customer an address names, matched as citext compares it, or null where no booking of
     * this tenant was made under it.
     */
    public function one(Tenant $tenant, string $email): ?CustomerRecord
    {
        return $this->customers($tenant, 'booking.customer_email = ?::citext', [$email], 'true', [], 1, 0)[0] ?? null;
    }

    /**
     * What the address's visits came to, the page's Spent line: the totals of its bookings in
     * confirmed or completed whose first start is at or before now, the visits the last visit is
     * read from, summed in each currency the rows carry, as a booking's total is never restated in
     * another; the currencies in alphabetical order, none where no visit is.
     *
     * @return list<array{amount_minor: int, currency: string}>
     */
    public function spent(Tenant $tenant, string $email): array
    {
        $shape = RowShape::of('customer spent', $tenant);
        $rows = $this->db->connection()->select(
            sprintf(
                'select booking.currency, sum(booking.total_minor)::bigint as amount from %s'
                .' where booking.customer_email = ?::citext and booking.status in (%s) and first.starts_at <= ?::timestamptz'
                .' group by booking.currency order by booking.currency',
                SqlText::bookingsByFirstStart($tenant->schema_name),
                self::statuses(self::VISITED),
            ),
            [$email, SqlText::instant($this->clock->now())],
        );

        return array_values(array_map(function (mixed $row) use ($shape): array {
            $row = $shape->row($row);

            return ['amount_minor' => $shape->int($row->amount), 'currency' => $shape->string($row->currency)];
        }, $rows));
    }

    /**
     * The search as a condition over an address's bookings, and what it binds.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function search(CustomerFilter $filter): array
    {
        if ($filter->search === null) {
            return ['true', []];
        }

        $like = '%'.addcslashes($filter->search, '\\%_').'%';
        $columns = array_map(
            fn (string $column): string => (self::SEARCHABLE[$column] ?? throw new LogicException(sprintf('The customers search cannot read "%s".', $column))).' ilike ?',
            $filter->searchIn,
        );

        return ['bool_or('.implode(' or ', ['false', ...$columns]).')', array_fill(0, count($columns), $like)];
    }

    /**
     * @param  list<string>  $whereBindings
     * @param  list<string>  $havingBindings
     * @param  ?int  $limit  null for every row, Postgres reading LIMIT NULL as no limit
     * @return list<CustomerRecord>
     */
    private function customers(Tenant $tenant, string $where, array $whereBindings, string $having, array $havingBindings, ?int $limit, int $offset): array
    {
        $sql = sprintf(
            'select (array_agg(booking.customer_email::text %1$s))[1] as email,'
            .' json_agg(booking.customer_name %1$s) as names,'
            .' json_agg(booking.customer_phone %1$s) filter (where booking.customer_phone is not null) as phones,'
            .' %2$s as first_visit, %3$s as last_visit, count(*) as bookings,'
            .' count(*) filter (where booking.status in (%8$s) and first.starts_at > ?::timestamptz) as upcoming,'
            .' count(*) filter (where booking.status = %9$s) as completed,'
            .' count(*) filter (where booking.status = %10$s) as no_shows,'
            .' count(*) filter (where booking.status = %11$s) as cancelled'
            .' from %4$s where %7$s and %5$s'
            .' group by booking.customer_email having %6$s'
            .' order by max(first.starts_at) desc, booking.customer_email'
            .' limit ? offset ?',
            self::RECENT_FIRST,
            SqlText::instantColumn('min(first.starts_at)'),
            SqlText::instantColumn('max(first.starts_at) filter (where booking.status in ('.self::statuses(self::VISITED).') and first.starts_at <= ?::timestamptz)'),
            SqlText::bookingsByFirstStart($tenant->schema_name),
            $where,
            $having,
            self::NAMED,
            self::statuses(self::UPCOMING),
            SqlText::literal(BookingStatus::Completed),
            SqlText::literal(BookingStatus::NoShow),
            SqlText::literal(BookingStatus::Cancelled),
        );
        $now = SqlText::instant($this->clock->now());

        // The select list binds before the rest: the last visit's now, then the upcoming count's.
        return array_values(array_map(
            fn (mixed $row): CustomerRecord => $this->record($tenant, $row),
            $this->db->connection()->select($sql, [$now, $now, ...$whereBindings, ...$havingBindings, $limit, $offset]),
        ));
    }

    /**
     * @param  non-empty-list<BookingStatus>  $statuses
     */
    private static function statuses(array $statuses): string
    {
        return implode(', ', array_map(SqlText::literal(...), $statuses));
    }

    private function record(Tenant $tenant, mixed $row): CustomerRecord
    {
        $shape = RowShape::of('customers', $tenant);
        $row = $shape->row($row);
        $email = $shape->string($row->email);
        $first = $shape->string($row->first_visit);
        $last = $row->last_visit === null ? null : $shape->string($row->last_visit);
        $bookings = $shape->int($row->bookings);
        $names = self::seen($row->names, $shape);

        if ($names === []) {
            throw $shape->misshapen();
        }

        return new CustomerRecord(
            $email,
            $names,
            $row->phones === null ? [] : self::seen($row->phones, $shape),
            SqlText::readInstant($first),
            $last === null ? null : SqlText::readInstant($last),
            $bookings,
            $shape->int($row->upcoming),
            $shape->int($row->completed),
            $shape->int($row->no_shows),
            $shape->int($row->cancelled),
        );
    }

    /**
     * A JSON array of text the server aggregated most recent first, as a list of each distinct
     * value once, at its most recent place.
     *
     * @return list<string>
     */
    private static function seen(mixed $json, RowShape $shape): array
    {
        $values = json_decode($shape->string($json), true);

        if (! is_array($values) || ! array_is_list($values)) {
            throw $shape->misshapen();
        }

        $texts = array_map($shape->string(...), $values);

        return array_values(array_unique($texts));
    }
}
