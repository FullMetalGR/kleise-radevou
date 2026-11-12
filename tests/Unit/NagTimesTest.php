<?php

use App\Application\Dto\HoldWindow;
use App\Application\Notification\NagTimes;

/*
 * When the three nags about a waiting request go: a quarter, a half and three quarters of the
 * way from the hold's start to its expiry, as elapsed time between the two instants and never
 * as a wall-clock unit. A clock change can lie inside a forty-eight hour window, and a quarter
 * of it by the wall clock would be a quarter of forty- seven or forty-nine hours; the queue
 * counts elapsed seconds, so the instants have to be the same kind of number.
 */

function nagTimesWindow(string $from = '2026-11-03 08:00:00', string $until = '2026-11-05 08:00:00', string $zone = 'UTC'): HoldWindow
{
    return new HoldWindow(instantAt($from, $zone), instantAt($until, $zone));
}

it('is three instants at a quarter, a half and three quarters of the window, in that order, spelled in UTC', function () {
    $times = NagTimes::of(nagTimesWindow());

    expect(array_map(fn (DateTimeImmutable $at): string => $at->format('Y-m-d\TH:i:sP'), $times))->toBe([
        '2026-11-03T20:00:00+00:00',
        '2026-11-04T08:00:00+00:00',
        '2026-11-04T20:00:00+00:00',
    ])
        ->and(array_map(fn (DateTimeImmutable $at): string => $at->getTimezone()->getName(), $times))->toBe(['UTC', 'UTC', 'UTC']);
});

it('measures the quarters as elapsed seconds from the start, so a forty-eight hour window is nagged twelve, twenty-four and thirty-six hours in', function () {
    $window = nagTimesWindow();
    $from = $window->from->getTimestamp();
    $until = $window->until->getTimestamp();

    expect(array_map(fn (DateTimeImmutable $at): int => $at->getTimestamp() - $from, NagTimes::of($window)))->toBe([12 * 3600, 24 * 3600, 36 * 3600])
        ->and(array_map(fn (DateTimeImmutable $at): int => $until - $at->getTimestamp(), NagTimes::of($window)))->toBe([36 * 3600, 24 * 3600, 12 * 3600]);
});

it('keeps the microseconds of the start, because the quarters are whole seconds added to it and nothing is rounded away', function () {
    $times = NagTimes::of(nagTimesWindow('2026-11-03 08:00:00.250000', '2026-11-05 08:00:00.250000'));

    expect($times[0]->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-03T20:00:00.250000+00:00');
});

it('counts elapsed time across the autumn clock change, so a window that spans it is quartered by the timeline and not by the wall clock', function () {
    // Athens leaves summer time on Sunday 25 October 2026 at 04:00, which becomes 03:00. A
    // request held on the Saturday at 10:00 EEST runs out on the Monday at 10:00 EET: the wall
    // clock counts forty-eight hours between the two and the timeline forty-nine. The queue
    // counts the timeline, so the half-way nag is at 24.5 hours of elapsed time, which the wall
    // clock calls 09:30 on the Sunday, and not at the Sunday's 10:00.
    $window = nagTimesWindow('2026-10-24 10:00:00', '2026-10-26 10:00:00', 'Europe/Athens');
    $times = NagTimes::of($window);
    $athens = new DateTimeZone('Europe/Athens');

    expect($window->from->format('P'))->toBe('+03:00')
        ->and($window->until->format('P'))->toBe('+02:00')
        ->and($window->until->getTimestamp() - $window->from->getTimestamp())->toBe(49 * 3600)
        ->and($times[1]->getTimestamp() - $window->from->getTimestamp())->toBe(24 * 3600 + 1800)
        ->and($times[1]->setTimezone($athens)->format('l j F Y \a\t H:i P'))->toBe('Sunday 25 October 2026 at 09:30 +02:00');
});

it('reads only the position on the timeline of the instants it is given, whatever zone they were spelled in', function () {
    $athens = NagTimes::of(nagTimesWindow('2026-11-03 10:00:00', '2026-11-05 10:00:00', 'Europe/Athens'));
    $utc = NagTimes::of(nagTimesWindow());

    expect(array_map(fn (DateTimeImmutable $at): int => $at->getTimestamp(), $athens))
        ->toBe(array_map(fn (DateTimeImmutable $at): int => $at->getTimestamp(), $utc));
});

it('rounds a quarter that is not a whole second down, which no window the insert writes ever has, because the insert writes whole minutes', function () {
    $times = NagTimes::of(nagTimesWindow('2026-11-03 08:00:00', '2026-11-03 08:00:10'));

    expect(array_map(fn (DateTimeImmutable $at): string => $at->format('H:i:s'), $times))->toBe(['08:00:02', '08:00:05', '08:00:07']);
});

it('refuses a window that ends before it starts, or at the same instant, because nothing can be a quarter of the way through it', function (string $until) {
    expect(fn () => new HoldWindow(instantAt('2026-11-03 08:00:00'), instantAt($until)))
        ->toThrow(InvalidArgumentException::class, 'window');
})->with(['before' => '2026-11-03 07:59:59', 'the same instant' => '2026-11-03 08:00:00']);
