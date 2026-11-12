<?php

use App\Domain\Availability\Occupancy;
use App\Domain\Availability\OfferedMinutes;

/*
 * The minutes the staff's hours offer over a run of dates, less the closures: Occupancy's
 * denominator, from the hours StaffHours::of answers, each window an elapsed span in the
 * tenant's zone so a clock change counts the hour it adds or takes.
 */

/**
 * One member's day as StaffHours::of answers it.
 *
 * @param  list<array{0: string, 1: string}>  $windows
 * @param  list<array{0: string|null, 1: string|null}>  $closures
 * @return array{windows: list<array{start: string, end: string}>, closures: list<array{start: string|null, end: string|null, reason: string|null, business: bool}>}
 */
function offeredDay(array $windows, array $closures = []): array
{
    return [
        'windows' => array_map(fn (array $window): array => ['start' => $window[0], 'end' => $window[1]], $windows),
        'closures' => array_map(fn (array $closure): array => ['start' => $closure[0], 'end' => $closure[1], 'reason' => null, 'business' => false], $closures),
    ];
}

it('counts a member\'s windows over the dates asked for and none outside them', function () {
    $hours = [7 => [
        '2026-10-20' => offeredDay([['09:00', '13:00'], ['14:00', '18:00']]),
        '2026-10-21' => offeredDay([['10:00', '12:30']]),
        '2026-10-22' => offeredDay([['09:00', '17:00']]),
    ]];
    $athens = new DateTimeZone('Europe/Athens');

    expect(OfferedMinutes::of($hours, $athens, '2026-10-20', '2026-10-21'))->toBe(480 + 150)
        ->and(OfferedMinutes::of($hours, $athens, '2026-10-22', '2026-10-22'))->toBe(480)
        ->and(OfferedMinutes::of($hours, $athens, '2026-10-23', '2026-10-30'))->toBe(0);
});

it('sums every member and counts a member with no hours as none', function () {
    $hours = [
        7 => ['2026-10-20' => offeredDay([['09:00', '17:00']])],
        8 => ['2026-10-20' => offeredDay([['12:00', '20:00']])],
        9 => ['2026-10-20' => offeredDay([])],
    ];

    expect(OfferedMinutes::of($hours, new DateTimeZone('Europe/Athens'), '2026-10-20', '2026-10-20'))->toBe(960);
});

/**
 * @param  list<array{0: string|null, 1: string|null}>  $closures
 */
function offeredWithClosures(array $closures): int
{
    return OfferedMinutes::of([7 => ['2026-10-20' => offeredDay([['09:00', '17:00']], $closures)]], new DateTimeZone('Europe/Athens'), '2026-10-20', '2026-10-20');
}

it('takes the closures away: a whole day, a part, one over a window\'s edge, two that overlap', function () {
    expect(offeredWithClosures([[null, null]]))->toBe(0)
        ->and(offeredWithClosures([['12:00', '13:00']]))->toBe(420)
        ->and(offeredWithClosures([['08:00', '10:00']]))->toBe(420)
        ->and(offeredWithClosures([['12:00', '14:00'], ['13:00', '15:00']]))->toBe(300)
        ->and(offeredWithClosures([['18:00', '20:00']]))->toBe(480)
        ->and(offeredWithClosures([['07:00', '19:00']]))->toBe(0);
});

it('counts a window as elapsed time in the zone, so the clocks going back add an hour and going forward take one', function () {
    $athens = new DateTimeZone('Europe/Athens');

    expect(OfferedMinutes::of([7 => ['2026-10-25' => offeredDay([['00:00', '24:00']])]], $athens, '2026-10-25', '2026-10-25'))->toBe(1500)
        ->and(OfferedMinutes::of([7 => ['2026-03-29' => offeredDay([['00:00', '24:00']])]], $athens, '2026-03-29', '2026-03-29'))->toBe(1380)
        ->and(OfferedMinutes::of([7 => ['2026-10-25' => offeredDay([['02:00', '06:00']])]], $athens, '2026-10-25', '2026-10-25'))->toBe(300)
        ->and(OfferedMinutes::of([7 => ['2026-10-24' => offeredDay([['00:00', '24:00']])]], $athens, '2026-10-24', '2026-10-24'))->toBe(1440);
});

it('offers a minute two overlapping windows share once, so a member booked across both reads full', function () {
    $athens = new DateTimeZone('Europe/Athens');
    $hours = [7 => ['2026-10-20' => offeredDay([['09:00', '13:00'], ['12:00', '17:00']])]];
    $start = (new DateTimeImmutable('2026-10-20 09:00', $athens))->getTimestamp();
    $end = (new DateTimeImmutable('2026-10-20 17:00', $athens))->getTimestamp();

    expect(Occupancy::byMember($hours, $athens, '2026-10-20', '2026-10-20', [['staff' => 7, 'start' => $start, 'end' => $end]]))
        ->toBe([7 => ['booked' => 480, 'available' => 480]]);
});
