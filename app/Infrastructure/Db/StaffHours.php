<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\CalendarWindow;
use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\Blackout;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\RuleExpander;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * The hours the Calendar draws: per member per date of a window, the windows in force and the
 * closures. The windows are the ones the open mask reads, through MasksFromRows::windowsOf, which
 * resolves each date through ShiftResolver from the patterns and the overrides, and
 * RuleExpander::inForce, the one seam the engine reads the hours through, so what the grid shows
 * as a member's day is what availability offers before closures and bookings; a change to what
 * inForce reads changes both. The closures are the blackouts of the dates, the member's own and
 * the tenant-wide ones, with their reasons, the whole day where no window is given.
 *
 * A window and a closure carry no instant: two wall-clock readings HH:MM, the end of the day
 * spelled 24:00, never converted. Every date of the window has an entry for every member asked
 * for, an empty day being two empty lists.
 *
 * Beside them the days as the Shifts day editor reads them, wherever it is mounted: each day's
 * source (the date's override, else the pattern, else none) and the windows its pattern gives
 * that date; and each member's next working day after a window, scanned through the same seam up
 * to eight weeks ahead, for the Calendar's Day column of a member with no hours.
 */
final readonly class StaffHours
{
    public const string PATTERN = 'pattern';

    public const string OVERRIDE = 'override';

    public const string NONE = 'none';

    /** How far the next working day is looked for: eight weeks. */
    public const int NEXT_WORKING_DAYS = 56;

    public function __construct(private DatabaseManager $db, private RuleExpander $expander = new RuleExpander) {}

    /**
     * @param  list<int>  $staffIds
     * @return array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>>
     */
    public function of(Tenant $tenant, array $staffIds, CalendarWindow $window): array
    {
        if ($staffIds === []) {
            return [];
        }

        $days = $window->days();
        $windows = MasksFromRows::forSchema($this->db->connection(), $tenant->schema_name)->windowsOf($staffIds, $days);

        return self::assemble($days, $staffIds, $windows, $this->closures($tenant, $staffIds, $days, $window), $this->expander);
    }

    /**
     * The days as the day editor reads them: of()'s windows and closures per member per date, with
     * the day's source, the date's override where one exists, else the pattern where it gives a
     * window, else none, and the windows the pattern gives that date, the override left out, which
     * Back to the pattern returns to, through inForce as of() reads them. The patterns and the
     * overrides are read once for all three (MasksFromRows::editableOf), and the day's source is
     * the override the resolver was handed.
     *
     * @param  list<int>  $staffIds
     * @return array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>, source: string, pattern: list<array{start: string, end: string}>}>>
     */
    public function editable(Tenant $tenant, array $staffIds, CalendarWindow $window): array
    {
        if ($staffIds === []) {
            return [];
        }

        $days = $window->days();
        $read = MasksFromRows::forSchema($this->db->connection(), $tenant->schema_name)->editableOf($staffIds, $days);
        $hours = self::assemble($days, $staffIds, $read['windows'], $this->closures($tenant, $staffIds, $days, $window), $this->expander);
        $patterned = self::assemble($days, $staffIds, $read['pattern'], [], $this->expander);
        $editable = [];

        foreach ($hours as $staffId => $dates) {
            foreach ($dates as $date => $day) {
                $editable[$staffId][$date] = [
                    ...$day,
                    'source' => isset($read['overridden'][$staffId][$date]) ? self::OVERRIDE : ($day['windows'] === [] ? self::NONE : self::PATTERN),
                    'pattern' => $patterned[$staffId][$date]['windows'] ?? [],
                ];
            }
        }

        return $editable;
    }

    /**
     * Each member's next working day from a date: the first date from $from on, inclusive, up to
     * NEXT_WORKING_DAYS of them, with a window in force that no whole-day closure takes, and its
     * windows, through of() and so the resolver seam; null where none falls in the eight weeks.
     * Every member asked for has an entry.
     *
     * The scan spans more than CalendarWindow::MAX_DAYS, the bound on what one request may ask
     * for: this is a read the server makes of its own, for a handful of idle members, never a
     * window a caller chose, so the bound does not apply. The window is built directly, which
     * the class allows, and accepts() is not asked.
     *
     * @param  list<int>  $staffIds
     * @return array<int, array{date: string, windows: list<array{start: string, end: string}>}|null>
     */
    public function nextWorking(Tenant $tenant, array $staffIds, string $from): array
    {
        if ($staffIds === []) {
            return [];
        }

        return self::firstWorking($staffIds, $this->of($tenant, $staffIds, new CalendarWindow($from, CalendarDay::plusDays($from, self::NEXT_WORKING_DAYS))));
    }

    /**
     * The pure half of nextWorking: per member the first date, in date order, holding a window
     * and no closure of the whole day, with its windows, or null.
     *
     * @param  list<int>  $staffIds
     * @param  array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>>  $hours
     * @return array<int, array{date: string, windows: list<array{start: string, end: string}>}|null>
     */
    public static function firstWorking(array $staffIds, array $hours): array
    {
        $next = [];

        foreach ($staffIds as $staffId) {
            $next[$staffId] = null;
            $dates = $hours[$staffId] ?? [];
            ksort($dates);

            foreach ($dates as $date => $day) {
                $closedAllDay = array_filter($day['closures'], fn (array $closure): bool => $closure['start'] === null) !== [];

                if ($day['windows'] !== [] && ! $closedAllDay) {
                    $next[$staffId] = ['date' => (string) $date, 'windows' => $day['windows']];

                    break;
                }
            }
        }

        return $next;
    }

    /**
     * The hours from rows already read and resolved, the pure half the unit test drives: for each
     * member and each date, the windows inForce answers over that date's resolved windows in their
     * order, and the closures of that date that reach the member, tenant-wide or their own, asked
     * of each row's Blackout, the one rule of which closure reaches whom.
     *
     * @param  list<string>  $days
     * @param  list<int>  $staffIds
     * @param  array<int, array<string, list<AvailabilityRule>>>  $windows  keyed by staff id, then by date
     * @param  list<array{staff_id: int|null, date: string, start: string|null, end: string|null, reason: string|null}>  $closures
     * @return array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>>
     */
    public static function assemble(array $days, array $staffIds, array $windows, array $closures, RuleExpander $expander = new RuleExpander): array
    {
        $hours = [];
        $blackouts = array_map(fn (array $closure): Blackout => new Blackout($closure['staff_id'], $closure['date']), $closures);

        foreach ($staffIds as $staffId) {
            foreach ($days as $day) {
                $inForce = array_map(
                    fn (AvailabilityRule $rule): array => ['start' => self::reading($rule->start->format()), 'end' => self::reading($rule->end->format())],
                    $expander->inForce($day, $windows[$staffId][$day] ?? []),
                );
                $reaching = [];

                foreach ($closures as $index => $closure) {
                    if ($blackouts[$index]->appliesTo($staffId, $day)) {
                        $reaching[] = ['start' => $closure['start'], 'end' => $closure['end'], 'reason' => $closure['reason'], 'business' => $closure['staff_id'] === null];
                    }
                }

                $hours[$staffId][$day] = ['windows' => $inForce, 'closures' => $reaching];
            }
        }

        return $hours;
    }

    /**
     * The members of the hours given with a window in force on at least one of its dates, in the
     * order given: Scheduled staff. A closure adds nobody and takes nobody away.
     *
     * @param  array<int, array<string, array{windows: list<mixed>, closures: list<mixed>}>>  $hours
     * @return list<int>
     */
    public static function scheduled(array $hours): array
    {
        $scheduled = [];

        foreach ($hours as $staffId => $days) {
            foreach ($days as $day) {
                if ($day['windows'] !== []) {
                    $scheduled[] = $staffId;

                    break;
                }
            }
        }

        return $scheduled;
    }

    /**
     * The blackouts of the dates that reach the members, the business's and their own, with their
     * reasons, in date order and then the whole day first.
     *
     * @param  list<int>  $staffIds
     * @param  list<string>  $days
     * @return list<array{staff_id: int|null, date: string, start: string|null, end: string|null, reason: string|null}>
     */
    private function closures(Tenant $tenant, array $staffIds, array $days, CalendarWindow $window): array
    {
        return array_values($this->db->connection()->table($tenant->schema_name.'.blackouts')
            ->whereBetween('date', [$days[0] ?? $window->from, $days[count($days) - 1] ?? $window->from])
            ->where(fn (Builder $query): Builder => $query->whereNull('staff_id')->orWhereRaw('staff_id = any(?::bigint[])', [SqlText::bigintArray($staffIds)]))
            ->orderBy('date')->orderByRaw('start_time nulls first')->orderBy('id')
            ->get(['staff_id', 'date', 'start_time', 'end_time', 'reason'])
            ->map(fn (object $row): array => [
                'staff_id' => ($row->staff_id ?? null) === null ? null : BlackoutRecords::int($row->staff_id),
                'date' => BlackoutRecords::string($row->date ?? null),
                'start' => BlackoutRecords::time($row->start_time ?? null),
                'end' => BlackoutRecords::time($row->end_time ?? null),
                'reason' => is_string($row->reason ?? null) ? $row->reason : null,
            ])
            ->all());
    }

    /** A wall-clock reading HH:MM:SS as the wire spells it, HH:MM. */
    private static function reading(string $time): string
    {
        return substr($time, 0, 5);
    }
}
