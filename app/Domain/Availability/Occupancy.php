<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeZone;

/**
 * Occupancy per member over a run of dates: the minutes the counted booking items fill, booked,
 * over the minutes the member offers, available.
 *
 * Available is the member's offered time (OfferedMinutes: the windows in force less the
 * closures, two windows that overlap joined so a minute they share is offered once), plus every minute of a counted item of theirs that falls outside it, before or after
 * a window, on a day with none, or inside a closure. Such a minute is booked and available both,
 * so booked never passes available and occupancy never passes 100 percent. A member who offers
 * nothing over the dates, an inactive one among them, is available exactly as long as they are
 * booked. Items are spans of Unix seconds; each sum is counted in seconds and given in whole
 * minutes.
 */
final class Occupancy
{
    /**
     * @param  array<int, array<string, array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}>>  $hours  per member, as StaffHours::of answers
     * @param  list<array{staff: int, start: int, end: int}>  $items  the counted items over the dates
     * @param  string  $from  the first date counted, Y-m-d
     * @param  string  $to  the last date counted, Y-m-d, inclusive
     * @return array<int, array{booked: int, available: int}> per member of the hours or the items, the hours' first
     */
    public static function byMember(array $hours, DateTimeZone $zone, string $from, string $to, array $items): array
    {
        $spans = [];
        $members = [];

        foreach ($hours as $member => $dates) {
            $spans[$member] = self::merged(OfferedMinutes::spans($dates, $zone, $from, $to));
            $members[$member] = ['booked' => 0, 'outside' => 0, 'offered' => array_sum(array_map(fn (array $span): int => intdiv($span[1] - $span[0], 60), $spans[$member]))];
        }

        foreach ($items as $item) {
            $length = max(0, $item['end'] - $item['start']);
            $inside = 0;

            foreach ($spans[$item['staff']] ?? [] as [$start, $end]) {
                $inside += max(0, min($end, $item['end']) - max($start, $item['start']));
            }

            $members[$item['staff']] ??= ['booked' => 0, 'outside' => 0, 'offered' => 0];
            $members[$item['staff']]['booked'] += $length;
            $members[$item['staff']]['outside'] += $length - $inside;
        }

        return array_map(fn (array $member): array => [
            'booked' => intdiv($member['booked'], 60),
            'available' => $member['offered'] + intdiv($member['outside'], 60),
        ], $members);
    }

    /**
     * Spans sorted and overlapping ones joined, so a minute two windows share is counted once.
     *
     * @param  list<array{0: int, 1: int}>  $spans
     * @return list<array{0: int, 1: int}>
     */
    private static function merged(array $spans): array
    {
        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($spans as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }
}
