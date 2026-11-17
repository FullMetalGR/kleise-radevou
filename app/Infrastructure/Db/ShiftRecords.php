<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\CalendarWindow;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\ShiftMinutes;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * The Shifts screen's rows: a week's hours per member as the screen reads them, and the three
 * writes, a member's pattern and one date's override, each replace-all for its row, beside the
 * Opening hours step's one week for every listed member (replaceEveryWeek). storedStart()
 * names the start the pattern write stores, for the request judging the pattern's end.
 *
 * The windows of a date are StaffHours', so the screen's day is the one the availability engine
 * offers and the Calendar draws, read through ShiftResolver and RuleExpander::inForce, with where
 * the day came from (the date's override, else the pattern, else none) and what the pattern gives
 * it, StaffHours::editable, the read the Calendar's Day mounts the day editor on too; this class
 * adds only what the screen shows beside them: the pattern rows as stored and the minutes, closures
 * subtracted (ShiftMinutes). The Staff list's Hours this week is the same read over the current
 * week (hoursOf).
 *
 * A member's week is their pattern alone. A write holding a window stamps
 * settings.hours_confirmed_at through WeeklyPattern::confirmHours, as StaffRecords::replaceWeek
 * does; a write leaving the row with no window, and the override's removal, stamp nothing, so
 * setup counts its Opening hours step done only once a user has saved a week that holds hours.
 */
final readonly class ShiftRecords
{
    public function __construct(private DatabaseManager $db, private StaffHours $hours) {}

    /**
     * The members the screen lists, in the roster's order: every active named member, or the
     * implicit member alone, standing for the business under the tenant's name, where the roster
     * has no active named member, each marked whether it is that implicit member, so the page tells a
     * tenant that held the staff feature from one that never did.
     *
     * @return list<array{id: int, name: string, is_implicit: bool}>
     */
    public function members(Tenant $tenant): array
    {
        return array_values($this->listed($tenant, false)->map(fn (stdClass $row): array => [
            'id' => BlackoutRecords::int($row->id),
            'name' => (bool) $row->is_implicit ? $tenant->name : BlackoutRecords::string($row->name),
            'is_implicit' => (bool) $row->is_implicit,
        ])->all());
    }

    /**
     * The one week the members given share as plain weekly patterns, each every week with no
     * end: its windows, the empty list where none of them holds a pattern, and null where they
     * differ, one holding a pattern another lacks or one of another cadence or with an end.
     *
     * @param  list<int>  $staffIds
     * @return list<array{weekday: int, start: string, end: string}>|null
     */
    public function sharedWeek(Tenant $tenant, array $staffIds): ?array
    {
        $stored = WeeklyPattern::stored($this->db->connection(), $tenant->schema_name.'.', $staffIds);

        if ($stored === []) {
            return [];
        }

        $weeks = array_map(fn (array $pattern): ?array => $pattern['cadence_weeks'] === 1 && $pattern['ends_on'] === null ? WeeklyPattern::windows($pattern['windows']) : null, $stored);
        $first = reset($weeks);

        return count($stored) === count($staffIds) && $first !== null && array_filter($weeks, fn (?array $week): bool => $week !== $first) === [] ? $first : null;
    }

    /**
     * Whether a row for one date is held, of any date, past or coming, a day off among them: by
     * any of the members given, or by the implicit member where none is named, the one whose week
     * Opening hours edits. A member given their hours by the day holds hours set by hand as one
     * with a pattern does, which a read of the patterns alone does not see.
     *
     * @param  list<int>|null  $staffIds
     */
    public function holdsDays(Tenant $tenant, ?array $staffIds = null): bool
    {
        return $this->table($tenant, 'shift_overrides')
            ->whereIn('staff_id', $staffIds ?? $this->table($tenant, 'staff')->where('is_implicit', true)->select('id'))
            ->exists();
    }

    /**
     * Gives every member the screen lists the one week given, as Opening hours gives the implicit
     * member theirs: a weekly pattern of those windows from the one start, or none for an empty
     * week (WeeklyPattern::replace), each member's dated overrides left as they are. One
     * transaction for the lot: the listed members are read with the lock lockMember takes, so
     * none is deleted under the write, and a failure on any one leaves every member's week as it
     * was. A week holding a window stamps the hours marker once.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $windows
     */
    public function replaceEveryWeek(Tenant $tenant, array $windows): void
    {
        $this->db->connection()->transaction(function () use ($tenant, $windows): void {
            $connection = $this->db->connection();
            $prefix = $tenant->schema_name.'.';

            foreach ($this->listed($tenant, true) as $row) {
                WeeklyPattern::replace($connection, $prefix, BlackoutRecords::int($row->id), $windows);
            }

            WeeklyPattern::confirmHours($connection, $prefix, $windows !== []);
        });
    }

    /**
     * The week's hours of the members given: per member per date the windows in force, their
     * source, the closures reaching the member and the windows the pattern gives that date, the
     * override left out (what Back to the pattern returns to); each member's pattern as stored
     * with its weekly minutes; and the minutes per date and per member, closures subtracted. The
     * windows, their source and what the pattern gives come from one read of the patterns and
     * one of the overrides (StaffHours::editable); the stored patterns are read beside them as
     * the rows hold them.
     *
     * @param  list<int>  $staffIds
     * @return array{
     *     days: list<array{staff_id: int, date: string, windows: list<array{start: string, end: string}>, source: string, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>, pattern: list<array{start: string, end: string}>}>,
     *     patterns: list<array{staff_id: int, cadence_weeks: int, starts_on: string, ends_on: string|null, windows: list<array{weekday: int, start: string, end: string}>, minutes: int}>,
     *     totals: array{days: list<array{date: string, minutes: int}>, members: list<array{staff_id: int, minutes: int}>}
     * }
     */
    public function week(Tenant $tenant, array $staffIds, CalendarWindow $window): array
    {
        $dates = $window->days();
        $hours = $this->hours->editable($tenant, $staffIds, $window);
        $days = [];
        $perDay = array_fill_keys($dates, 0);
        $perMember = [];

        foreach ($staffIds as $staffId) {
            $perMember[$staffId] = 0;

            foreach ($dates as $date) {
                $day = $hours[$staffId][$date] ?? ['windows' => [], 'closures' => [], 'source' => StaffHours::NONE, 'pattern' => []];
                $minutes = ShiftMinutes::ofDay($day['windows'], $day['closures']);
                $perDay[$date] += $minutes;
                $perMember[$staffId] += $minutes;
                $days[] = [
                    'staff_id' => $staffId,
                    'date' => $date,
                    'windows' => $day['windows'],
                    'source' => $day['source'],
                    'closures' => $day['closures'],
                    'pattern' => $day['pattern'],
                ];
            }
        }

        return [
            'days' => $days,
            'patterns' => $this->patterns($tenant, $staffIds),
            'totals' => [
                'days' => array_map(fn (string $date): array => ['date' => $date, 'minutes' => $perDay[$date]], $dates),
                'members' => array_map(fn (int $staffId): array => ['staff_id' => $staffId, 'minutes' => $perMember[$staffId]], $staffIds),
            ],
        ];
    }

    /**
     * Hours this week on the Staff list, the parts of its one sentence per member: whether this
     * week is the pattern's own week, every week (a pattern of one week, no override on a date of
     * the week, and the week's windows the pattern's whole), and then the pattern's weekly minutes,
     * closures not subtracted, since they are named beside; otherwise the week's minutes as worked,
     * closures subtracted; and the week's closures that reach the member, the business's and their
     * own, by date, each its date and window, both null all day.
     *
     * @param  list<int>  $staffIds
     * @return array<int, array{minutes: int, weekly: bool, closures: list<array{date: string, start: string|null, end: string|null}>}>
     */
    public function hoursOf(Tenant $tenant, array $staffIds, CalendarWindow $window): array
    {
        $week = $this->week($tenant, $staffIds, $window);
        $patterns = [];

        foreach ($week['patterns'] as $pattern) {
            $patterns[$pattern['staff_id']] = $pattern;
        }

        $days = [];

        foreach ($week['days'] as $day) {
            $days[$day['staff_id']][] = $day;
        }

        $hours = [];

        foreach ($week['totals']['members'] as $member) {
            $staffId = $member['staff_id'];
            $pattern = $patterns[$staffId] ?? null;
            $planned = 0;
            $overridden = false;
            $closures = [];

            foreach ($days[$staffId] ?? [] as $day) {
                $planned += ShiftMinutes::ofDay($day['windows'], []);
                $overridden = $overridden || $day['source'] === StaffHours::OVERRIDE;

                foreach ($day['closures'] as $closure) {
                    $closures[] = ['date' => $day['date'], 'start' => $closure['start'], 'end' => $closure['end']];
                }
            }

            $weekly = $pattern !== null && $pattern['cadence_weeks'] === 1 && ! $overridden && $planned === $pattern['minutes'];
            $hours[$staffId] = ['minutes' => $weekly ? $planned : $member['minutes'], 'weekly' => $weekly, 'closures' => $closures];
        }

        return $hours;
    }

    /**
     * The start replacePattern stores for a pattern of the cadence and start given, the one start
     * at a cadence of one and the start given above it (WeeklyPattern::storedStart, which put()
     * writes): the pattern request judges an end against it, so a save is never judged against a
     * start it does not store.
     */
    public function storedStart(Tenant $tenant, int $cadenceWeeks, string $startsOn): string
    {
        return WeeklyPattern::storedStart($this->db->connection(), $tenant->schema_name.'.', $cadenceWeeks, $startsOn);
    }

    /**
     * Replaces the member's pattern whole, stamping the hours marker where the windows hold one: a
     * weekly one from the one start whatever start the body names, a cadence above one from the
     * start given (WeeklyPattern::put). A pattern of no window is no pattern, as a week with no
     * window is none for WeeklyPattern::replace, so the member is left with none. False where no
     * such member exists.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $windows
     */
    public function replacePattern(Tenant $tenant, int $staffId, int $cadenceWeeks, string $startsOn, ?string $endsOn, array $windows): bool
    {
        return $this->db->connection()->transaction(function () use ($tenant, $staffId, $cadenceWeeks, $startsOn, $endsOn, $windows): bool {
            if (! $this->lockMember($tenant, $staffId)) {
                return false;
            }

            $connection = $this->db->connection();
            $prefix = $tenant->schema_name.'.';
            if ($windows === []) {
                WeeklyPattern::drop($connection, $prefix, $staffId);
            } else {
                WeeklyPattern::put($connection, $prefix, $staffId, $windows, $cadenceWeeks, $startsOn, $endsOn);
            }

            WeeklyPattern::confirmHours($connection, $prefix, $windows !== []);

            return true;
        });
    }

    /**
     * Replaces the member's override of one date whole, an empty list being not working that
     * day. False where no such member exists.
     *
     * @param  list<array{start: string, end: string}>  $windows
     */
    public function replaceDay(Tenant $tenant, int $staffId, string $date, array $windows): bool
    {
        return $this->db->connection()->transaction(function () use ($tenant, $staffId, $date, $windows): bool {
            if (! $this->lockMember($tenant, $staffId)) {
                return false;
            }

            WeeklyPattern::override($this->db->connection(), $tenant->schema_name.'.', $staffId, $date, $windows);
            WeeklyPattern::confirmHours($this->db->connection(), $tenant->schema_name.'.', $windows !== []);

            return true;
        });
    }

    /**
     * Drops the member's override of one date, the day going back to the pattern; a date with
     * none is left as it is. False where no such member exists.
     */
    public function dropDay(Tenant $tenant, int $staffId, string $date): bool
    {
        return $this->db->connection()->transaction(function () use ($tenant, $staffId, $date): bool {
            if (! $this->lockMember($tenant, $staffId)) {
                return false;
            }

            $this->table($tenant, 'shift_overrides')->where('staff_id', $staffId)->where('date', CalendarDay::spelled($date))->delete();

            return true;
        });
    }

    /**
     * Holds the member's row against a delete for the rest of the write, with the lock a booking
     * item's key share does not wait on, as StaffRecords' photo writes take; answers whether the
     * member exists.
     */
    private function lockMember(Tenant $tenant, int $staffId): bool
    {
        return $this->table($tenant, 'staff')->where('id', $staffId)->lock('for no key update')->value('id') !== null;
    }

    /**
     * The rows of the members the screen lists, in the roster's order (members()), locked as
     * lockMember locks one where asked.
     *
     * @return Collection<int, stdClass>
     */
    private function listed(Tenant $tenant, bool $lock): Collection
    {
        $query = $this->table($tenant, 'staff')->where('status', 'active')->orderBy('sort')->orderBy('id');

        if ($lock) {
            $query->lock('for no key update');
        }

        $rows = $query->get(['id', 'name', 'is_implicit']);
        $named = $rows->filter(fn (stdClass $row): bool => ! (bool) $row->is_implicit);

        return $named->isEmpty() ? $rows : $named;
    }

    /**
     * The members' patterns as stored, in the order of the ids given, with each one's minutes over
     * a week of its windows.
     *
     * @param  list<int>  $staffIds
     * @return list<array{staff_id: int, cadence_weeks: int, starts_on: string, ends_on: string|null, windows: list<array{weekday: int, start: string, end: string}>, minutes: int}>
     */
    private function patterns(Tenant $tenant, array $staffIds): array
    {
        $stored = WeeklyPattern::stored($this->db->connection(), $tenant->schema_name.'.', $staffIds);

        return array_values(array_filter(array_map(
            fn (int $staffId): ?array => isset($stored[$staffId]) ? ['staff_id' => $staffId, ...$stored[$staffId], 'minutes' => ShiftMinutes::ofWindows($stored[$staffId]['windows'])] : null,
            $staffIds,
        )));
    }

    private function table(Tenant $tenant, string $name): Builder
    {
        return $this->db->connection()->table($tenant->schema_name.'.'.$name);
    }
}
