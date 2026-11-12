<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\Blackout;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\ShiftOverride;
use App\Domain\Availability\ShiftPattern;
use App\Domain\Availability\ShiftResolver;
use App\Domain\Availability\SlotSpan;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\StaffDayMasks;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Enum\BookingStatus;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use stdClass;

/**
 * The one mapping from rows to masks: four statements for the whole window across every staff
 * member asked for, never one per staff-day, and then MaskBuilder per staff-day through the
 * window's geometry. The loads are read here too, the same rows counted per member instead of
 * painted. The shift patterns of those members with the tenant's week start beside them, and
 * their overrides on the window's dates, which ShiftResolver turns into each staff-day's windows
 * in force; the blackouts of the window's dates, tenant-wide and personal to those members; and
 * the blocking items of those members whose block range overlaps the window, read as a range over
 * block_span.
 *
 * The two halves of a staff-day's masks are read apart, because the mask cache reads them apart:
 * the open half comes from the tables that carry no revision, settings (the week start),
 * shift_patterns, shift_overrides and blackouts, and is rebuilt on every read, the not-booked
 * half is the one the items statement feeds and the one the cache keeps per staff-day revision.
 * build joins them for a caller that wants both cold.
 *
 * Two callers, one code path, a connection and a table-name prefix apart. A read outside any
 * transaction names every table by schema and sets nothing on the connection, the way the
 * settings reader honours the pooling rule. The re-validation inside a booking transaction runs
 * on that transaction's connection, where the runner has already set and asserted the search
 * path, so its prefix is empty.
 *
 * The blocking predicate is spelled here, once, in the items statement: confirmed, or held with
 * an expiry still ahead of the server's clock, or an approval request whose timeout policy
 * auto-approves. It reads status, expiry and policy and never the blocks flag, so a lapsed hold
 * is free by time comparison with no sweeper having run, and a row whose flag has drifted reads
 * as its booking says. The write protocol keeps reading the flag under lock, because it is what
 * the exclusion constraint checks.
 *
 * Beside each item the statement answers the instant from which the clock alone may change what
 * the rows say about it, hold_expires_at for every item that is not confirmed: for an item the
 * middle clause admits and the third does not, the hold's expiry, which frees it; for one the
 * third admits, live or lapsed, the request's window, which frees nothing, since the third clause
 * still admits it, and past which the sweeper may grant it into a payment hold of its own expiry
 * with no revision bumped; and null for a confirmed item, which only a write frees. Per staff-day
 * the earliest such instant among the items that cleared a slot on that day travels with the
 * not-booked mask, so the cache can bound an entry's life by it.
 *
 * Every overlapping item is handed to every day of the window and the geometry clips: an item
 * crossing local midnight clears the tail of one day and the head of the next, and one starting
 * exactly at a midnight is empty on the day before.
 */
final readonly class MasksFromRows
{
    private function __construct(
        private Connection $connection,
        private string $prefix,
        private MaskBuilder $builder = new MaskBuilder,
        private ShiftResolver $shifts = new ShiftResolver,
    ) {}

    /**
     * For a read outside any transaction: every table named by schema, nothing set on the
     * connection.
     */
    public static function forSchema(Connection $connection, string $schemaName): self
    {
        return new self($connection, '"'.$schemaName.'".');
    }

    /**
     * For a caller whose connection already carries the tenant's search path: a transaction the
     * runner entered.
     */
    public static function onSearchPath(Connection $connection): self
    {
        return new self($connection, '');
    }

    /**
     * Both halves of every staff-day, cold: the four statements, patterns, overrides, blackouts
     * and items, nothing cached.
     *
     * @param  non-empty-list<int>  $staffIds
     */
    public function build(WindowGeometry $window, array $staffIds): StaffDayMaskSet
    {
        $factors = $this->openFactors($window, $staffIds);
        $notBooked = $this->notBookedMasks($window, $staffIds);
        $byStaff = [];

        foreach ($factors as $staffId => $days) {
            $pairs = [];

            foreach ($days as $position => [$hours, $unclosed]) {
                $pairs[] = new StaffDayMasks($hours->and($unclosed), $notBooked[$staffId][$position]->mask, $hours, $unclosed);
            }

            $byStaff[$staffId] = $pairs;
        }

        return new StaffDayMaskSet($window, $byStaff);
    }

    /**
     * The open half alone, the patterns, overrides and blackouts statements: keyed by staff id,
     * each member once in the order asked, one mask per day of the window in its order, each
     * day's windows the resolver's.
     *
     * @param  non-empty-list<int>  $staffIds
     * @return non-empty-array<int, list<FreeBusyMask>>
     */
    public function openMasks(WindowGeometry $window, array $staffIds): array
    {
        return array_map(
            fn (array $days): array => array_map(fn (array $pair): FreeBusyMask => $pair[0]->and($pair[1]), $days),
            $this->openFactors($window, $staffIds),
        );
    }

    /**
     * The open half's two factors per staff-day, from the same three statements: the member's
     * hours, the patterns and overrides in force expanded, and what no blackout reaching them
     * covers. Their intersection is the open mask; a business move asks them apart which of the
     * two a range crosses, the advisory read as well as the write under the lock.
     *
     * @param  non-empty-list<int>  $staffIds
     * @return non-empty-array<int, list<array{FreeBusyMask, FreeBusyMask}>>
     */
    public function openFactors(WindowGeometry $window, array $staffIds): array
    {
        $staffIds = self::staffIds($staffIds);
        $ids = SqlText::bigintArray($staffIds);
        $firstDay = $window->days[0];
        $lastDay = $window->days[count($window->days) - 1];

        $inForce = $this->windowsOn($staffIds, array_map(fn (StaffDayGeometry $day): string => $day->day, $window->days));
        $blackouts = $this->blackouts($ids, $firstDay->day, $lastDay->day);
        $factors = [];

        foreach ($staffIds as $staffId) {
            $days = [];

            foreach ($window->days as $day) {
                $days[] = [$this->builder->hours($day, $inForce[$staffId][$day->day] ?? []), $this->builder->unclosed($day, $staffId, $blackouts)];
            }

            $factors[$staffId] = $days;
        }

        return $factors;
    }

    /**
     * The not-booked half alone, the items statement: keyed by staff id, each member once in
     * the order asked, one mask per day of the window in its order, each with the earliest
     * expiry among the live held items that cleared a slot on that day.
     *
     * Given a booking's identifier, that booking's items are left out of the statement, so the
     * masks are the rows read as if it were not there: the per-booking availability read, which
     * rebuilds the staff-days a booking stands on rather than subtracting it from a cached mask.
     * The write protocol never passes one.
     *
     * @param  non-empty-list<int>  $staffIds
     * @param  string|null  $leavingOut  the uuid of a booking whose items count as free, or null
     * @return non-empty-array<int, list<NotBookedMask>>
     */
    public function notBookedMasks(WindowGeometry $window, array $staffIds, ?string $leavingOut = null): array
    {
        $staffIds = self::staffIds($staffIds);
        $firstDay = $window->days[0];
        $lastDay = $window->days[count($window->days) - 1];

        $items = $this->items(SqlText::bigintArray($staffIds), $firstDay->midnight, $lastDay->nextMidnight, $leavingOut);
        $notBooked = [];

        foreach ($staffIds as $staffId) {
            $days = [];

            foreach ($window->days as $day) {
                $days[] = $this->notBookedOn($day, $items[$staffId] ?? []);
            }

            $notBooked[$staffId] = $days;
        }

        return $notBooked;
    }

    /**
     * The load of each member on one day: the count of their blocking items whose block
     * range overlaps the day, by the one predicate, in one statement. A member with none
     * is not returned.
     *
     * @param  non-empty-list<int>  $staffIds
     * @return array<int, int> by staff id
     */
    public function loads(StaffDayGeometry $day, array $staffIds): array
    {
        $staffIds = self::staffIds($staffIds);
        $loads = [];

        foreach ($this->connection->select(self::loadsSql($this->prefix), [SqlText::bigintArray($staffIds), SqlText::instant($day->midnight), SqlText::instant($day->nextMidnight)], false) as $row) {
            $row = self::row($row);
            $staffId = $row->staff_id ?? null;
            $load = $row->load ?? null;

            if (! is_int($staffId) || ! is_int($load)) {
                throw new LogicException('The loads statement returned a row not shaped as staff_id and load.');
            }

            $loads[$staffId] = $load;
        }

        return $loads;
    }

    /**
     * The patterns statement: the settings row's week start, and beside it each of the members'
     * patterns, or nulls on the one row where none of them has one, so the week start comes back
     * whatever the members hold.
     */
    public static function patternsSql(string $prefix): string
    {
        return 'select s.start_of_week, p.staff_id, p.starts_on::text as starts_on, p.cadence_weeks,'
            .' p.ends_on::text as ends_on, p.windows::text as windows'
            .' from '.$prefix.'settings s'
            .' left join '.$prefix.'shift_patterns p on p.staff_id = any(?::bigint[])'
            .' where s.id = 1'
            .' order by p.staff_id';
    }

    public static function overridesSql(string $prefix): string
    {
        return 'select staff_id, date::text as date, windows::text as windows'
            .' from '.$prefix.'shift_overrides'
            .' where date between ?::date and ?::date'
            .' and staff_id = any(?::bigint[])'
            .' order by staff_id, date';
    }

    public static function blackoutsSql(string $prefix): string
    {
        return 'select staff_id, date, start_time, end_time'
            .' from '.$prefix.'blackouts'
            .' where date between ?::date and ?::date'
            .' and (staff_id is null or staff_id = any(?::bigint[]))'
            .' order by date, id';
    }

    /**
     * The items statement: the members' items whose block range overlaps the window, joined to
     * their booking so the predicate can read it, each instant handed back as UTC text with its
     * offset whatever zone the session runs in, and beside each item the instant from which the
     * clock alone may change what the rows say about it, or null for a confirmed item, which only
     * a write changes. Leaving a booking out adds one condition naming it by its identifier,
     * bound last, and changes nothing else.
     */
    public static function itemsSql(string $prefix, bool $leavingOut = false): string
    {
        return 'select i.staff_id,'
            .' '.SqlText::instantColumn('i.block_start_utc').' as block_start,'
            .' '.SqlText::instantColumn('i.block_end_utc').' as block_end,'
            .' case when '.self::confirmedClause().' then null'
            .' else '.SqlText::instantColumn('b.hold_expires_at').' end as hold_expiry'
            .' from '.$prefix.'booking_items i'
            .' join '.$prefix.'bookings b on b.id = i.booking_id'
            .' where i.staff_id = any(?::bigint[])'
            ." and i.block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)')"
            .' and ('.self::blockingPredicate().')'
            .($leavingOut ? ' and b.uuid <> ?::uuid' : '')
            .' order by i.staff_id, i.block_start_utc, i.id';
    }

    /**
     * The loads statement: the same rows the items statement reads, counted per member
     * rather than handed over, the predicate applied the same way.
     */
    public static function loadsSql(string $prefix): string
    {
        return 'select i.staff_id, count(*)::int as load'
            .' from '.$prefix.'booking_items i'
            .' join '.$prefix.'bookings b on b.id = i.booking_id'
            .' where i.staff_id = any(?::bigint[])'
            ." and i.block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)')"
            .' and ('.self::blockingPredicate().')'
            .' group by i.staff_id'
            .' order by i.staff_id';
    }

    /**
     * The one blocking predicate, over the booking joined as b: its three clauses, each
     * spelled once below and reused by the expiry column.
     */
    public static function blockingPredicate(): string
    {
        return self::confirmedClause().' or '.self::liveHoldClause().' or '.self::autoApproveClause();
    }

    private static function confirmedClause(): string
    {
        return 'b.status = '.SqlText::literal(BookingStatus::Confirmed);
    }

    private static function liveHoldClause(): string
    {
        return '(b.status in ('.implode(', ', array_map(SqlText::literal(...), BookingStatus::held())).') and b.hold_expires_at > now())';
    }

    private static function autoApproveClause(): string
    {
        return '(b.status = '.SqlText::literal(BookingStatus::AwaitingApproval)." and b.on_approval_timeout = 'auto_approve')";
    }

    /**
     * One staff-day's not-booked half from the member's items over the window: the mask, and
     * the earliest expiry among the items that cleared at least one slot of this day. An item
     * the geometry clips to nothing here counted nothing here, so its expiry bounds nothing.
     *
     * @param  list<array{BlockRange, DateTimeImmutable|null}>  $items
     */
    private function notBookedOn(StaffDayGeometry $day, array $items): NotBookedMask
    {
        $ranges = [];
        $earliest = null;

        foreach ($items as [$range, $expiry]) {
            $ranges[] = $range;

            if ($expiry === null || SlotSpan::outward($day, $range->start, $range->end)->isEmpty()) {
                continue;
            }

            if ($earliest === null || $expiry < $earliest) {
                $earliest = $expiry;
            }
        }

        return new NotBookedMask($this->builder->notBooked($day, $ranges), $earliest);
    }

    /**
     * The windows in force per member per date for the members and dates given, through the
     * patterns and overrides statements and ShiftResolver, as the open mask reads them: for the
     * one other reader of the hours, StaffHours, so the Calendar's windows cannot part from
     * what the engine offers. Every member and date asked for has an entry, an empty list where
     * nothing is in force.
     *
     * @param  list<int>  $staffIds
     * @param  list<string>  $days  YYYY-MM-DD in order
     * @return array<int, array<string, list<AvailabilityRule>>> keyed by staff id, then by date
     */
    public function windowsOf(array $staffIds, array $days): array
    {
        return $staffIds === [] ? [] : $this->windowsOn(self::staffIds($staffIds), $days);
    }

    /**
     * windowsOf's windows and, beside them, the windows each member's pattern gives per date with
     * the overrides left out, what a date edited alone goes back to, and the dates each member's
     * override covers: what an editor of a day shows, read through ShiftResolver as windowsOf
     * reads it, so the cadence keeps its one spelling, in one read of the patterns and one of the
     * overrides. Every member and date asked for has an entry in both lists of windows.
     *
     * @param  list<int>  $staffIds
     * @param  list<string>  $days  YYYY-MM-DD in order
     * @return array{windows: array<int, array<string, list<AvailabilityRule>>>, pattern: array<int, array<string, list<AvailabilityRule>>>, overridden: array<int, array<string, true>>}
     */
    public function editableOf(array $staffIds, array $days): array
    {
        $staffIds = $staffIds === [] ? [] : self::staffIds($staffIds);

        if ($staffIds === [] || $days === []) {
            return ['windows' => [], 'pattern' => [], 'overridden' => []];
        }

        $ids = SqlText::bigintArray($staffIds);
        [$startOfWeek, $patterns] = $this->patterns($ids);
        $overrides = $this->overrides($ids, $days[0], $days[count($days) - 1]);
        $windows = [];
        $pattern = [];
        $overridden = [];

        foreach ($staffIds as $staffId) {
            foreach ($days as $day) {
                $windows[$staffId][$day] = $this->shifts->windowsOn($patterns[$staffId] ?? null, $overrides[$staffId] ?? [], $startOfWeek, $day);
                $pattern[$staffId][$day] = $this->shifts->windowsOn($patterns[$staffId] ?? null, [], $startOfWeek, $day);

                if (isset($overrides[$staffId][$day])) {
                    $overridden[$staffId][$day] = true;
                }
            }
        }

        return ['windows' => $windows, 'pattern' => $pattern, 'overridden' => $overridden];
    }

    /**
     * @param  list<int>  $staffIds  each a positive integer, each once
     * @param  list<string>  $days
     * @return array<int, array<string, list<AvailabilityRule>>>
     */
    private function windowsOn(array $staffIds, array $days): array
    {
        if ($staffIds === [] || $days === []) {
            return [];
        }

        $ids = SqlText::bigintArray($staffIds);
        [$startOfWeek, $patterns] = $this->patterns($ids);
        $overrides = $this->overrides($ids, $days[0], $days[count($days) - 1]);
        $windows = [];

        foreach ($staffIds as $staffId) {
            foreach ($days as $day) {
                $windows[$staffId][$day] = $this->shifts->windowsOn($patterns[$staffId] ?? null, $overrides[$staffId] ?? [], $startOfWeek, $day);
            }
        }

        return $windows;
    }

    /**
     * The tenant's week start and the members' patterns keyed by staff id.
     *
     * @return array{int, array<int, ShiftPattern>}
     */
    private function patterns(string $ids): array
    {
        $startOfWeek = null;
        $patterns = [];

        foreach ($this->connection->select(self::patternsSql($this->prefix), [$ids], false) as $row) {
            $row = self::row($row);
            $startOfWeek = $row->start_of_week ?? null;
            $staffId = $row->staff_id ?? null;

            if (! is_int($startOfWeek)) {
                throw new LogicException('The settings row is not shaped as the schema declares.');
            }

            if ($staffId === null) {
                continue;
            }

            $startsOn = $row->starts_on ?? null;
            $cadence = $row->cadence_weeks ?? null;
            $endsOn = $row->ends_on ?? null;
            $windows = $row->windows ?? null;

            if (! is_int($staffId) || ! is_string($startsOn) || ! is_int($cadence) || ($endsOn !== null && ! is_string($endsOn)) || ! is_string($windows)) {
                throw new LogicException('A shift_patterns row is not shaped as the schema declares.');
            }

            $patterns[$staffId] = new ShiftPattern($startsOn, $cadence, $endsOn, array_map(
                fn (array $window): AvailabilityRule => new AvailabilityRule(self::weekday($window), ...self::window($window)),
                self::windowList($windows),
            ));
        }

        if ($startOfWeek === null) {
            throw new LogicException('The schema holds no settings row to read the week start from.');
        }

        return [$startOfWeek, $patterns];
    }

    /**
     * The members' overrides on the window's dates, keyed by staff id and then by date.
     *
     * @return array<int, array<string, ShiftOverride>>
     */
    private function overrides(string $ids, string $firstDay, string $lastDay): array
    {
        $overrides = [];

        foreach ($this->connection->select(self::overridesSql($this->prefix), [$firstDay, $lastDay, $ids], false) as $row) {
            $row = self::row($row);
            $staffId = $row->staff_id ?? null;
            $date = $row->date ?? null;
            $windows = $row->windows ?? null;

            if (! is_int($staffId) || ! is_string($date) || ! is_string($windows)) {
                throw new LogicException('A shift_overrides row is not shaped as the schema declares.');
            }

            $overrides[$staffId][CalendarDay::spelled($date)] = ShiftOverride::of($date, array_map(self::window(...), self::windowList($windows)));
        }

        return $overrides;
    }

    /**
     * A windows column decoded: a list of objects, refused otherwise.
     *
     * @return list<array<string, mixed>>
     */
    private static function windowList(string $json): array
    {
        $windows = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        if (! is_array($windows) || ! array_is_list($windows)) {
            throw new LogicException('A windows column does not hold a list.');
        }

        $entries = [];

        foreach ($windows as $window) {
            if (! is_array($window)) {
                throw new LogicException('A windows column holds an entry that is not an object.');
            }

            $entry = [];

            foreach ($window as $key => $value) {
                $entry[(string) $key] = $value;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private static function weekday(array $window): int
    {
        $weekday = $window['weekday'] ?? null;

        if (! is_int($weekday)) {
            throw new LogicException('A pattern window names no weekday.');
        }

        return $weekday;
    }

    /**
     * A window's start and end readings.
     *
     * @param  array<string, mixed>  $window
     * @return array{WallClockTime, WallClockTime}
     */
    private static function window(array $window): array
    {
        $start = $window['start'] ?? null;
        $end = $window['end'] ?? null;

        if (! is_string($start) || ! is_string($end)) {
            throw new LogicException('A window names no start or no end.');
        }

        return [WallClockTime::of($start), WallClockTime::of($end)];
    }

    /**
     * @return list<Blackout>
     */
    private function blackouts(string $ids, string $firstDay, string $lastDay): array
    {
        $blackouts = [];

        foreach ($this->connection->select(self::blackoutsSql($this->prefix), [$firstDay, $lastDay, $ids], false) as $row) {
            $row = self::row($row);
            $staffId = $row->staff_id ?? null;
            $date = $row->date ?? null;
            $start = $row->start_time ?? null;
            $end = $row->end_time ?? null;

            if (($staffId !== null && ! is_int($staffId)) || ! is_string($date)
                || ($start !== null && ! is_string($start)) || ($end !== null && ! is_string($end))) {
                throw new LogicException('A blackouts row is not shaped as the schema declares.');
            }

            $blackouts[] = new Blackout(
                $staffId,
                $date,
                $start === null ? null : WallClockTime::of($start),
                $end === null ? null : WallClockTime::of($end),
            );
        }

        return $blackouts;
    }

    /**
     * The blocking items of the members over the window, each as its range and the expiry
     * that alone will free it.
     *
     * @return array<int, list<array{BlockRange, DateTimeImmutable|null}>> keyed by staff id
     */
    private function items(string $ids, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, ?string $leavingOut): array
    {
        $items = [];

        $bindings = [$ids, SqlText::instant($windowStart), SqlText::instant($windowEnd)];

        if ($leavingOut !== null) {
            $bindings[] = $leavingOut;
        }

        foreach ($this->connection->select(self::itemsSql($this->prefix, $leavingOut !== null), $bindings, false) as $row) {
            $row = self::row($row);
            $staffId = $row->staff_id ?? null;
            $start = $row->block_start ?? null;
            $end = $row->block_end ?? null;
            $expiry = $row->hold_expiry ?? null;

            if (! is_int($staffId) || ! is_string($start) || ! is_string($end) || ($expiry !== null && ! is_string($expiry))) {
                throw new LogicException('A booking_items row is not shaped as the schema declares.');
            }

            $items[$staffId][] = [
                new BlockRange(SqlText::readInstant($start), SqlText::readInstant($end)),
                $expiry === null ? null : SqlText::readInstant($expiry),
            ];
        }

        return $items;
    }

    /**
     * The staff ids asked for, each a positive integer, each once, in the order given.
     *
     * @param  list<int>  $staffIds
     * @return non-empty-list<int>
     */
    private static function staffIds(array $staffIds): array
    {
        $unique = array_values(array_unique($staffIds));

        if ($unique === []) {
            throw new InvalidArgumentException('An availability read names at least one staff member.');
        }

        foreach ($unique as $staffId) {
            if ($staffId < 1) {
                throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
            }
        }

        return $unique;
    }

    private static function row(mixed $row): stdClass
    {
        if (! $row instanceof stdClass) {
            throw new LogicException(sprintf('A statement returned a %s where a row was expected.', get_debug_type($row)));
        }

        return $row;
    }
}
