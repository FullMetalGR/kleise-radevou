<?php

use App\Domain\Availability\Granularity;
use App\Domain\Availability\SlotSpan;
use App\Domain\Availability\StaffDayGeometry;

function spanDay(string $day = '2026-03-28', int $minutes = 5): StaffDayGeometry
{
    return StaffDayGeometry::of($day, new DateTimeZone('Europe/Athens'), Granularity::minutes($minutes));
}

function spanInstant(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('Europe/Athens'));
}

it('rounds outward: the floor of the start and the ceiling of the end, so 09:03 to 09:17 spans 09:00 to 09:20 at five minutes', function () {
    $span = SlotSpan::outward(spanDay(), spanInstant('2026-03-28 09:03'), spanInstant('2026-03-28 09:17'));

    expect($span->from)->toBe(108)
        ->and($span->to)->toBe(112)
        ->and($span->isEmpty())->toBeFalse()
        ->and(spanDay()->instantAt(108)->format('H:i'))->toBe('09:00')
        ->and(spanDay()->instantAt(112)->format('H:i'))->toBe('09:20');
});

it('rounds inward: the ceiling of the start and the floor of the end, so 09:03 to 09:17 spans 09:05 to 09:15 at five minutes', function () {
    $span = SlotSpan::inward(spanDay(), spanInstant('2026-03-28 09:03'), spanInstant('2026-03-28 09:17'));

    expect($span->from)->toBe(109)
        ->and($span->to)->toBe(111);
});

it('leaves a range already on the grid where it is, whichever way it rounds', function () {
    $from = spanInstant('2026-03-28 09:00');
    $to = spanInstant('2026-03-28 10:00');

    expect(SlotSpan::outward(spanDay(), $from, $to))->toEqual(SlotSpan::inward(spanDay(), $from, $to))
        ->and(SlotSpan::outward(spanDay(), $from, $to)->from)->toBe(108)
        ->and(SlotSpan::outward(spanDay(), $from, $to)->to)->toBe(120);
});

it('rounds a microsecond past a slot start outward to the neighbours and inward to nothing', function () {
    $from = spanInstant('2026-03-28 09:00:00.000001');
    $to = spanInstant('2026-03-28 09:04:59.999999');

    $outward = SlotSpan::outward(spanDay(), $from, $to);
    $inward = SlotSpan::inward(spanDay(), $from, $to);

    expect([$outward->from, $outward->to])->toBe([108, 109])
        ->and($inward->isEmpty())->toBeTrue();
});

it('clips a range crossing the next midnight to the width on this day, and to the start on the next', function () {
    $from = spanInstant('2026-03-28 23:30');
    $to = spanInstant('2026-03-29 00:30');

    $today = SlotSpan::outward(spanDay('2026-03-28'), $from, $to);
    $tomorrow = SlotSpan::outward(spanDay('2026-03-29'), $from, $to);

    expect([$today->from, $today->to])->toBe([282, 288])
        ->and([$tomorrow->from, $tomorrow->to])->toBe([0, 6]);
});

it('clips a range ending exactly at the next midnight to the width, and one starting there to nothing', function () {
    $day = spanDay('2026-03-28');

    $ending = SlotSpan::outward($day, spanInstant('2026-03-28 23:00'), $day->nextMidnight);
    $starting = SlotSpan::outward($day, $day->nextMidnight, spanInstant('2026-03-29 01:00'));

    expect([$ending->from, $ending->to])->toBe([276, 288])
        ->and($starting->isEmpty())->toBeTrue()
        ->and(SlotSpan::inward($day, spanInstant('2026-03-28 23:00'), $day->nextMidnight)->to)->toBe(288);
});

it('is empty for a range entirely before the day or entirely after it', function () {
    $day = spanDay('2026-03-28');

    expect(SlotSpan::outward($day, spanInstant('2026-03-27 09:00'), spanInstant('2026-03-27 17:00'))->isEmpty())->toBeTrue()
        ->and(SlotSpan::outward($day, spanInstant('2026-03-29 09:00'), spanInstant('2026-03-29 17:00'))->isEmpty())->toBeTrue()
        ->and(SlotSpan::inward($day, spanInstant('2026-03-27 09:00'), spanInstant('2026-03-27 17:00'))->isEmpty())->toBeTrue()
        ->and(SlotSpan::inward($day, spanInstant('2026-03-29 09:00'), spanInstant('2026-03-29 17:00'))->isEmpty())->toBeTrue();
});

it('covers the whole day for a range that starts before it and ends after it', function () {
    $day = spanDay('2026-03-29');
    $span = SlotSpan::outward($day, spanInstant('2026-03-27 09:00'), spanInstant('2026-04-01 17:00'));

    expect([$span->from, $span->to])->toBe([0, 276]);
});

it('is empty, and never inverted, for an end that falls before its start', function () {
    $day = spanDay();
    $span = SlotSpan::outward($day, spanInstant('2026-03-28 10:00'), spanInstant('2026-03-28 09:00'));

    expect($span->isEmpty())->toBeTrue()
        ->and($span->from)->toBeLessThanOrEqual($span->to);
});

it('indexes instants, not wall-clock readings, so the two autumn readings of 03:30 give two different spans', function () {
    $autumn = spanDay('2026-10-25');
    $utc = new DateTimeZone('UTC');
    $first = SlotSpan::outward($autumn, new DateTimeImmutable('2026-10-25T03:30:00+03:00', $utc), new DateTimeImmutable('2026-10-25T03:40:00+03:00', $utc));
    $second = SlotSpan::outward($autumn, new DateTimeImmutable('2026-10-25T03:30:00+02:00', $utc), new DateTimeImmutable('2026-10-25T03:40:00+02:00', $utc));

    expect([$first->from, $first->to])->toBe([42, 44])
        ->and([$second->from, $second->to])->toBe([54, 56]);
});
