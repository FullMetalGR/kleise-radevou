<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * The minutes a member works on one date: the length of the day's windows less what its
 * closures cover, a closure with no window covering the whole day. Two closures over the same
 * stretch take it away once, and a closure outside every window takes nothing. Shifts' totals
 * and the Staff list's Hours this week are both this sum, so the two never spell a different
 * figure for the same week.
 *
 * A window and a closure are wall-clock readings HH:MM with no instant, the end of the day
 * spelled 24:00; the figure counts the wall clock, so a clock-change day counts as its readings
 * say.
 */
final readonly class ShiftMinutes
{
    private const int DAY = 1440;

    /**
     * @param  list<array{start: string, end: string}>  $windows
     * @param  list<array{start: string|null, end: string|null}>  $closures
     */
    public static function ofDay(array $windows, array $closures): int
    {
        $closed = [];

        foreach ($closures as $closure) {
            $closed[] = $closure['start'] === null || $closure['end'] === null
                ? [0, self::DAY]
                : [self::minutes($closure['start']), self::minutes($closure['end'])];
        }

        $total = 0;

        foreach ($windows as $window) {
            $total += self::open(self::minutes($window['start']), self::minutes($window['end']), $closed);
        }

        return $total;
    }

    /**
     * The minutes of a repeating week's windows, closures not counted, since a pattern names no
     * date: the pattern's own figure, "15 hours a week".
     *
     * @param  list<array{start: string, end: string}>  $windows
     */
    public static function ofWindows(array $windows): int
    {
        return self::ofDay($windows, []);
    }

    /**
     * The minutes of one window left once the closed stretches are taken out of it.
     *
     * @param  list<array{int, int}>  $closed
     */
    private static function open(int $start, int $end, array $closed): int
    {
        $covered = [];

        foreach ($closed as [$from, $to]) {
            $from = max($from, $start);
            $to = min($to, $end);

            if ($from < $to) {
                $covered[] = [$from, $to];
            }
        }

        usort($covered, fn (array $left, array $right): int => $left <=> $right);
        $taken = 0;
        $reached = $start;

        foreach ($covered as [$from, $to]) {
            $from = max($from, $reached);

            if ($from < $to) {
                $taken += $to - $from;
                $reached = $to;
            }
        }

        return max(0, $end - $start) - $taken;
    }

    /** A reading HH:MM, or HH:MM:SS, as minutes into the day, 24:00 as the day's whole length. */
    private static function minutes(string $reading): int
    {
        return intdiv(WallClockTime::of($reading)->secondsIntoDay, 60);
    }
}
