<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\CalendarWindow;
use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\Overridable;
use App\Infrastructure\Db\BlackoutRecords;
use App\Infrastructure\Db\CalendarRoster;
use App\Infrastructure\Db\StaffHours;
use App\Tenancy\Tenant;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The crossings a move would make, as the wire spells them with the facts a warning names: the
 * base crossing ErrorEnvelope::crossingsOf writes, its segment, member and kind, and beside it
 * what is in the way, so the admin can say it in plain words without the calendar's window of
 * hours and closures loaded. Facts and never sentences: the client words them in the viewer's
 * language and the tenant's formats.
 *
 * Every crossing carries `date`, the tenant's date its service range starts on, and three facts,
 * empty where they do not apply:
 *
 * - `hours`, for a crossing of working hours: the member's windows in force that date, HH:MM,
 *   which the range runs outside of; empty when the member has none that date.
 * - `day_off`, for a crossing of working hours with no window that date: `weekday` when the
 *   member's pattern holding the date gives that weekday no window whatever its cadence, their
 *   own day off; `business` when, besides, no active member's pattern holding the date gives
 *   the weekday one either, the business closed that day of the week; else `date`, the date
 *   alone being off: an override takes it, the member's pattern works the weekday but skips
 *   this week, or no pattern of theirs holds the date. Null when there are windows.
 * - `closures`, for a crossing of a closure: each closure in the way, the business's or the
 *   member's, as its whole run of dates (until null for one date), its window (null for the
 *   whole day) and its reason. A closure is in the way when its window meets the range on a
 *   date the range touches; were none to, every closure reaching those dates is named instead,
 *   so a crossing is never left without one.
 *
 * Read in one go for any number of crossing lists, the day's candidate starts among them: one
 * read of hours and one of closures over the dates they touch.
 */
final readonly class CrossingReasons
{
    public function __construct(
        private StaffHours $hours,
        private BlackoutRecords $blackouts,
        private CalendarRoster $roster,
    ) {}

    /**
     * @param  list<MaskCrossing>  $crossings
     * @return list<array<string, mixed>>
     */
    public function of(Tenant $tenant, array $crossings): array
    {
        return $this->ofEach($tenant, [$crossings])[0];
    }

    /**
     * @param  list<list<MaskCrossing>>  $lists
     * @return list<list<array<string, mixed>>>
     */
    public function ofEach(Tenant $tenant, array $lists): array
    {
        $all = array_merge(...$lists);

        if ($all === []) {
            return array_map(fn (): array => [], $lists);
        }

        $zone = $tenant->zone();
        $dates = array_merge(...array_map(fn (MaskCrossing $crossing): array => self::datesOf($crossing, $zone), $all));
        sort($dates);
        $from = $dates[0];
        $to = $dates[count($dates) - 1];
        $working = self::staffOf($all, Overridable::Hours);
        $closed = self::staffOf($all, Overridable::Closure);
        $weekly = [];
        $days = [];

        if ($working !== []) {
            $weekly = array_map(fn (array $member): int => $member['id'], $this->roster->active($tenant));
            $implicit = $weekly === [] ? $this->roster->implicitId($tenant) : null;
            $weekly = $implicit === null ? $weekly : [$implicit];
            $read = array_values(array_unique([...$working, ...$weekly]));
            $days = $this->hours->explained($tenant, $read, new CalendarWindow($from, self::dayAfter($to)));
        }

        $runs = $closed === [] ? [] : $this->blackouts->runsReaching($tenant, $closed, $from, $to);

        return array_map(
            fn (array $crossings): array => array_map(fn (MaskCrossing $crossing): array => self::reasonsOf($crossing, $zone, $days, $weekly, $runs), $crossings),
            $lists,
        );
    }

    /**
     * One crossing with its facts, from the hours and closures already read: the pure half of
     * the read.
     *
     * @param  array<int, array<string, array{windows: list<array{start: string, end: string}>, overridden: bool, usual: list<array{start: string, end: string}>|null}>>  $days  per member per date the windows in force, whether an override covers the date, and the member's usual hours that weekday, null where no pattern holds the date
     * @param  list<int>  $weekly  the members whose usual weeks say whether the business opens a weekday
     * @param  list<array{staff_id: int|null, date: string, until: string|null, start: string|null, end: string|null, reason: string|null}>  $runs
     * @return array{segment: int, staff_id: int, kind: string, date: string, hours: list<array{start: string, end: string}>, day_off: string|null, closures: list<array{business: bool, date: string, until: string|null, start: string|null, end: string|null, reason: string|null}>}
     */
    public static function reasonsOf(MaskCrossing $crossing, DateTimeZone $zone, array $days, array $weekly, array $runs): array
    {
        $date = $crossing->start->setTimezone($zone)->format('Y-m-d');
        $reasons = [
            'segment' => $crossing->segment,
            'staff_id' => $crossing->staffId,
            'kind' => $crossing->kind->value,
            'date' => $date,
            'hours' => [],
            'day_off' => null,
            'closures' => [],
        ];

        if ($crossing->kind === Overridable::Hours) {
            $day = $days[$crossing->staffId][$date] ?? null;
            $reasons['hours'] = $day['windows'] ?? [];
            $reasons['day_off'] = $reasons['hours'] !== [] ? null : self::dayOff($date, $day, $days, $weekly);

            return $reasons;
        }

        $reasons['closures'] = array_map(fn (array $run): array => [
            'business' => $run['staff_id'] === null,
            'date' => $run['date'],
            'until' => $run['until'],
            'start' => $run['start'],
            'end' => $run['end'],
            'reason' => $run['reason'],
        ], self::inTheWay($crossing, $zone, $runs));

        return $reasons;
    }

    /**
     * Why a member has no window on a date. The date alone is off unless the member's usual
     * week is read and gives the weekday nothing: an override took the date, the usual week
     * works the weekday and the pattern skips this one, or no pattern holds the date. A weekday
     * the member never works is their own day off, and the business's where none of the members
     * who make its week works it either.
     *
     * @param  array{overridden: bool, usual: list<array{start: string, end: string}>|null}|null  $day  the member's own day
     * @param  array<int, array<string, array{usual: list<array{start: string, end: string}>|null}>>  $days
     * @param  list<int>  $weekly
     */
    private static function dayOff(string $date, ?array $day, array $days, array $weekly): string
    {
        if ($day === null || $day['overridden'] || $day['usual'] !== []) {
            return 'date';
        }

        foreach ($weekly as $member) {
            if (($days[$member][$date]['usual'] ?? []) !== []) {
                return 'weekday';
            }
        }

        return 'business';
    }

    /**
     * The runs of closures of the business and of the crossing's member whose window meets the
     * service range on a date it touches, else every one of theirs reaching those dates.
     *
     * @param  list<array{staff_id: int|null, date: string, until: string|null, start: string|null, end: string|null, reason: string|null}>  $runs
     * @return list<array{staff_id: int|null, date: string, until: string|null, start: string|null, end: string|null, reason: string|null}>
     */
    private static function inTheWay(MaskCrossing $crossing, DateTimeZone $zone, array $runs): array
    {
        $dates = self::datesOf($crossing, $zone);
        $first = $dates[0];
        $last = $dates[count($dates) - 1];
        $from = $crossing->start->setTimezone($zone)->format('H:i');
        $to = $crossing->end->setTimezone($zone)->format('Y-m-d') === $last ? $crossing->end->setTimezone($zone)->format('H:i') : '24:00';
        $reaching = [];
        $meeting = [];

        foreach ($runs as $run) {
            if ($run['staff_id'] !== null && $run['staff_id'] !== $crossing->staffId) {
                continue;
            }

            $on = array_values(array_filter($dates, fn (string $date): bool => $run['date'] <= $date && $date <= ($run['until'] ?? $run['date'])));

            if ($on === []) {
                continue;
            }

            $reaching[] = $run;

            foreach ($on as $date) {
                $opens = $date === $first ? $from : '00:00';
                $closes = $date === $last ? $to : '24:00';

                if ($run['start'] === null || $run['end'] === null || ($run['start'] < $closes && $run['end'] > $opens)) {
                    $meeting[] = $run;

                    break;
                }
            }
        }

        return $meeting === [] ? $reaching : $meeting;
    }

    /**
     * The tenant's dates a crossing's service range touches, its first to the one its last
     * minute falls on.
     *
     * @return non-empty-list<string>
     */
    private static function datesOf(MaskCrossing $crossing, DateTimeZone $zone): array
    {
        $day = $crossing->start->setTimezone($zone)->format('Y-m-d');
        $last = $crossing->end->sub(new DateInterval('PT1S'))->setTimezone($zone)->format('Y-m-d');
        $dates = [$day];

        while ($day < $last) {
            $day = self::dayAfter($day);
            $dates[] = $day;
        }

        return $dates;
    }

    /**
     * The members the crossings of one kind name, each once.
     *
     * @param  list<MaskCrossing>  $crossings
     * @return list<int>
     */
    private static function staffOf(array $crossings, Overridable $kind): array
    {
        $ids = [];

        foreach ($crossings as $crossing) {
            if ($crossing->kind === $kind) {
                $ids[$crossing->staffId] = $crossing->staffId;
            }
        }

        return array_values($ids);
    }

    /** The date after a machine date, by the calendar and never by elapsed time. */
    private static function dayAfter(string $date): string
    {
        return (new DateTimeImmutable($date.' 12:00', new DateTimeZone('UTC')))->add(new DateInterval('P1D'))->format('Y-m-d');
    }
}
