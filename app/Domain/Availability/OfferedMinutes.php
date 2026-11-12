<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The minutes the staff's hours offer over a run of dates, less the closures: Occupancy's
 * denominator.
 *
 * It reads the hours as StaffHours::of answers them, per member per date the windows in force
 * and the closures reaching the member, and sums every member's dates from the first given to
 * the last, both inclusive. A closure with no window is the whole day; the closures of a date are
 * taken away as their union, so two that overlap take their overlap once. What is left of each
 * window is counted as elapsed time in the zone, its wall-clock ends read on its date and 24:00
 * as the next midnight, so a day the clocks change on offers the hour it gains or loses.
 */
final class OfferedMinutes
{
    private const int MIDNIGHT = 1440;

    /**
     * @param  array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>>  $hours
     * @param  string  $from  the first date counted, Y-m-d
     * @param  string  $to  the last date counted, Y-m-d, inclusive
     */
    public static function of(array $hours, DateTimeZone $zone, string $from, string $to): int
    {
        $minutes = 0;

        foreach ($hours as $dates) {
            foreach (self::spans($dates, $zone, $from, $to) as [$start, $end]) {
                $minutes += intdiv($end - $start, 60);
            }
        }

        return $minutes;
    }

    /**
     * One member's offered time over the dates from the first given to the last, both inclusive,
     * as spans of Unix seconds, each what is left of a window once the closures are taken away.
     *
     * @param  array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>  $dates
     * @return list<array{0: int, 1: int}>
     */
    public static function spans(array $dates, DateTimeZone $zone, string $from, string $to): array
    {
        $spans = [];

        foreach ($dates as $date => $day) {
            $date = (string) $date;

            if ($date < $from || $date > $to) {
                continue;
            }

            foreach (self::open($day['windows'], $day['closures']) as [$start, $end]) {
                $spans[] = [self::instant($date, $start, $zone)->getTimestamp(), self::instant($date, $end, $zone)->getTimestamp()];
            }
        }

        return $spans;
    }

    /**
     * The windows with the closures taken away, as minutes of the day.
     *
     * @param  list<array{start: string, end: string}>  $windows
     * @param  list<array{start: string|null, end: string|null, reason: string|null, business: bool}>  $closures
     * @return list<array{0: int, 1: int}>
     */
    private static function open(array $windows, array $closures): array
    {
        $open = array_map(fn (array $window): array => [self::minute($window['start']), self::minute($window['end'])], $windows);

        foreach ($closures as $closure) {
            $cut = $closure['start'] === null || $closure['end'] === null ? [0, self::MIDNIGHT] : [self::minute($closure['start']), self::minute($closure['end'])];
            $left = [];

            foreach ($open as [$start, $end]) {
                if ($cut[0] > $start) {
                    $left[] = [$start, min($end, $cut[0])];
                }

                if ($cut[1] < $end) {
                    $left[] = [max($start, $cut[1]), $end];
                }
            }

            $open = array_values(array_filter($left, fn (array $span): bool => $span[1] > $span[0]));
        }

        return $open;
    }

    /** A wall-clock reading HH:MM as minutes from midnight, 24:00 the day's end. */
    private static function minute(string $reading): int
    {
        return (int) substr($reading, 0, 2) * 60 + (int) substr($reading, 3, 2);
    }

    private static function instant(string $date, int $minute, DateTimeZone $zone): DateTimeImmutable
    {
        if ($minute >= self::MIDNIGHT) {
            return (new DateTimeImmutable($date.' 00:00', $zone))->add(new DateInterval('P1D'));
        }

        return new DateTimeImmutable(sprintf('%s %02d:%02d', $date, intdiv($minute, 60), $minute % 60), $zone);
    }
}
