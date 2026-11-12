<?php

use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\WallClockTime;

function wallClockDay(string $day, string $zone = 'Europe/Athens'): StaffDayGeometry
{
    return StaffDayGeometry::of($day, new DateTimeZone($zone), Granularity::default());
}

it('reads {text} as {seconds} seconds into the day and spells it back with seconds', function (string $text, int $seconds, string $spelled) {
    $time = WallClockTime::of($text);

    expect($time->secondsIntoDay)->toBe($seconds)
        ->and($time->format())->toBe($spelled);
})->with([
    'midnight' => ['00:00', 0, '00:00:00'],
    'nine' => ['09:00', 9 * 3600, '09:00:00'],
    'nine with seconds' => ['09:00:00', 9 * 3600, '09:00:00'],
    'odd seconds' => ['09:03:17', 9 * 3600 + 3 * 60 + 17, '09:03:17'],
    'the last second' => ['23:59:59', 86399, '23:59:59'],
    'the end of the day' => ['24:00', 86400, '24:00:00'],
    'the end of the day with seconds' => ['24:00:00', 86400, '24:00:00'],
]);

it('refuses {text}, which is not a wall-clock time', function (string $text) {
    expect(fn () => WallClockTime::of($text))->toThrow(InvalidArgumentException::class, sprintf('A wall-clock time is spelled HH:MM or HH:MM:SS, from 00:00 to 24:00, not "%s".', $text));
})->with([
    'an unpadded hour' => ['9:00'],
    'a sixtieth minute' => ['09:60'],
    'a sixtieth second' => ['09:00:60'],
    'past the end of the day' => ['24:00:01'],
    'a twenty-fifth hour' => ['25:00'],
    'a fraction' => ['09:00:00.5'],
    'a word' => ['noon'],
    'an empty string' => [''],
]);

it('orders two times and knows when they are equal', function () {
    $nine = WallClockTime::of('09:00');
    $five = WallClockTime::of('17:00');

    expect($nine->isBefore($five))->toBeTrue()
        ->and($five->isBefore($nine))->toBeFalse()
        ->and($nine->isBefore(WallClockTime::of('09:00:00')))->toBeFalse()
        ->and($nine->equals(WallClockTime::of('09:00:00')))->toBeTrue()
        ->and($nine->equals($five))->toBeFalse();
});

it('resolves to the instant of that wall-clock reading on the day, in the tenant zone, with the offset in force', function () {
    $ordinary = wallClockDay('2026-03-28');
    $spring = wallClockDay('2026-03-29');
    $autumn = wallClockDay('2026-10-25');

    expect(WallClockTime::of('09:00')->instantOn($ordinary)->format(DATE_RFC3339))->toBe('2026-03-28T09:00:00+02:00')
        ->and(WallClockTime::of('00:00')->instantOn($ordinary)->getTimestamp())->toBe($ordinary->midnight->getTimestamp())
        ->and(WallClockTime::of('01:00')->instantOn($spring)->format(DATE_RFC3339))->toBe('2026-03-29T01:00:00+02:00')
        ->and(WallClockTime::of('05:00')->instantOn($spring)->format(DATE_RFC3339))->toBe('2026-03-29T05:00:00+03:00')
        ->and(WallClockTime::of('01:00')->instantOn($autumn)->format(DATE_RFC3339))->toBe('2026-10-25T01:00:00+03:00')
        ->and(WallClockTime::of('05:00')->instantOn($autumn)->format(DATE_RFC3339))->toBe('2026-10-25T05:00:00+02:00')
        ->and(WallClockTime::of('09:00')->instantOn($ordinary)->getTimezone()->getName())->toBe('Europe/Athens');
});

it('resolves the end of the day to the next midnight, on a day the clocks change included', function () {
    foreach (['2026-03-28', '2026-03-29', '2026-10-25'] as $day) {
        $geometry = wallClockDay($day);

        expect(WallClockTime::of('24:00')->instantOn($geometry)->getTimestamp())->toBe($geometry->nextMidnight->getTimestamp());
    }
});

it('pins how PHP resolves a reading the clocks skip: forward by the width of the gap, so 03:30 on the spring day is 04:30 in the new offset', function () {
    // This pins the parser's behaviour rather than asserting a preference. A rule written for
    // 03:30 on a day with no 03:30 has no right answer; what matters is that the answer is one
    // instant, on the day, and the same every time.
    $spring = wallClockDay('2026-03-29');

    expect(WallClockTime::of('03:30')->instantOn($spring)->format(DATE_RFC3339))->toBe('2026-03-29T04:30:00+03:00')
        ->and(WallClockTime::of('03:00')->instantOn($spring)->format(DATE_RFC3339))->toBe('2026-03-29T04:00:00+03:00')
        ->and(WallClockTime::of('02:59:59')->instantOn($spring)->format(DATE_RFC3339))->toBe('2026-03-29T02:59:59+02:00');
});

it('pins how PHP resolves a reading the clocks repeat: the second pass, so 03:30 on the autumn day is in the new offset', function () {
    $autumn = wallClockDay('2026-10-25');

    expect(WallClockTime::of('03:30')->instantOn($autumn)->format(DATE_RFC3339))->toBe('2026-10-25T03:30:00+02:00')
        ->and(WallClockTime::of('03:00')->instantOn($autumn)->format(DATE_RFC3339))->toBe('2026-10-25T03:00:00+02:00');
});

it('lands on the first instant of a day whose local midnight the clocks skip', function () {
    $santiago = wallClockDay('2024-09-08', 'America/Santiago');

    expect(WallClockTime::of('00:00')->instantOn($santiago)->getTimestamp())->toBe($santiago->midnight->getTimestamp())
        ->and(WallClockTime::of('00:30')->instantOn($santiago)->format(DATE_RFC3339))->toBe('2024-09-08T01:30:00-03:00');
});

it('reads as the instant it would fall on if one offset held all day, which is what the clipped passes of a blackout are built from', function () {
    $autumn = wallClockDay('2026-10-25');
    $spring = wallClockDay('2026-03-29');

    expect(WallClockTime::of('03:30')->instantUnder($autumn, 10800)->format(DATE_RFC3339))->toBe('2026-10-25T03:30:00+03:00')
        ->and(WallClockTime::of('03:30')->instantUnder($autumn, 7200)->format(DATE_RFC3339))->toBe('2026-10-25T03:30:00+02:00')
        ->and(WallClockTime::of('03:30')->instantUnder($spring, 7200)->getTimestamp())->toBe((new DateTimeImmutable('2026-03-29 01:30', new DateTimeZone('UTC')))->getTimestamp())
        ->and(WallClockTime::of('24:00')->instantUnder($autumn, 7200)->getTimestamp())->toBe($autumn->nextMidnight->getTimestamp())
        ->and(WallClockTime::of('24:00')->instantUnder($autumn, 10800)->getTimestamp())->toBe($autumn->nextMidnight->getTimestamp() - 3600)
        ->and(WallClockTime::of('09:00')->instantUnder(wallClockDay('2026-03-28'), 7200)->getTimestamp())->toBe(WallClockTime::of('09:00')->instantOn(wallClockDay('2026-03-28'))->getTimestamp())
        ->and(WallClockTime::of('09:00')->instantUnder($autumn, 7200)->getTimezone()->getName())->toBe('Europe/Athens');
});
