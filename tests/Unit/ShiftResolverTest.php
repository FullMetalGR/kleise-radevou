<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\InvalidShiftPattern;
use App\Domain\Availability\ShiftOverride;
use App\Domain\Availability\ShiftPattern;
use App\Domain\Availability\ShiftResolver;
use App\Domain\Availability\WallClockTime;

/*
 * The shift resolver: a member's windows for a date are the override's where one exists for the
 * date, an empty one meaning not working; else the pattern's windows for the date's weekday when
 * the date is inside the pattern's dates and the weeks from the week holding starts_on to the
 * week holding the date, on the tenant's week start, are a multiple of the cadence; else none.
 *
 * 2026-11-02 is a Monday, 2026-11-01 a Sunday.
 */

/**
 * @param  list<array{int, string, string}>  $windows
 */
function resolverPattern(string $startsOn, int $cadence = 1, ?string $endsOn = null, array $windows = [[1, '09:00', '17:00'], [3, '10:00', '14:00'], [3, '15:00', '19:00'], [7, '12:00', '16:00']]): ShiftPattern
{
    return new ShiftPattern($startsOn, $cadence, $endsOn, array_map(
        fn (array $window): AvailabilityRule => new AvailabilityRule($window[0], WallClockTime::of($window[1]), WallClockTime::of($window[2])),
        $windows,
    ));
}

/**
 * The windows in force as [weekday, start, end] readings, for comparing.
 *
 * @param  list<AvailabilityRule>  $rules
 * @return list<array{int, string, string}>
 */
function resolverReadings(array $rules): array
{
    return array_map(fn (AvailabilityRule $rule): array => [$rule->weekday, $rule->start->format(), $rule->end->format()], $rules);
}

/**
 * @return list<array{int, string, string}>
 */
function shiftResolved(?ShiftPattern $pattern, string $date, int $startOfWeek = 1, ShiftOverride ...$overrides): array
{
    $byDate = [];

    foreach ($overrides as $override) {
        $byDate[$override->date] = $override;
    }

    return resolverReadings((new ShiftResolver)->windowsOn($pattern, $byDate, $startOfWeek, $date));
}

it('answers the weekly pattern\'s windows for the date\'s weekday, both of a split day, and nothing on a weekday the pattern leaves out', function () {
    $pattern = resolverPattern('2026-11-02');

    expect(shiftResolved($pattern, '2026-11-02'))->toBe([[1, '09:00:00', '17:00:00']])
        ->and(shiftResolved($pattern, '2026-11-04'))->toBe([[3, '10:00:00', '14:00:00'], [3, '15:00:00', '19:00:00']])
        ->and(shiftResolved($pattern, '2026-11-03'))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-08'))->toBe([[7, '12:00:00', '16:00:00']])
        ->and(shiftResolved($pattern, '2027-06-07'))->toBe([[1, '09:00:00', '17:00:00']]);
});

it('answers nothing for a member with no pattern and no override', function () {
    expect(shiftResolved(null, '2026-11-02'))->toBe([]);
});

it('lets the date\'s override win over the pattern: its windows on the date\'s weekday, an empty one not working, and the pattern again on every other date', function () {
    $pattern = resolverPattern('2026-11-02');
    $changed = ShiftOverride::of('2026-11-02', [[WallClockTime::of('12:00'), WallClockTime::of('14:00')], [WallClockTime::of('16:00'), WallClockTime::of('24:00')]]);
    $off = ShiftOverride::of('2026-11-04', []);
    $extra = ShiftOverride::of('2026-11-03', [[WallClockTime::of('08:00'), WallClockTime::of('10:00')]]);

    expect(shiftResolved($pattern, '2026-11-02', 1, $changed, $off, $extra))->toBe([[1, '12:00:00', '14:00:00'], [1, '16:00:00', '24:00:00']])
        ->and(shiftResolved($pattern, '2026-11-04', 1, $changed, $off, $extra))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-03', 1, $changed, $off, $extra))->toBe([[2, '08:00:00', '10:00:00']])
        ->and(shiftResolved($pattern, '2026-11-09', 1, $changed, $off, $extra))->toBe([[1, '09:00:00', '17:00:00']])
        ->and(shiftResolved(null, '2026-11-03', 1, $extra))->toBe([[2, '08:00:00', '10:00:00']]);
});

it('answers nothing before starts_on, even inside its week, and from starts_on on', function () {
    $pattern = resolverPattern('2026-11-04');

    expect(shiftResolved($pattern, '2026-11-02'))->toBe([])
        ->and(shiftResolved($pattern, '2026-10-26'))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-04'))->toBe([[3, '10:00:00', '14:00:00'], [3, '15:00:00', '19:00:00']])
        ->and(shiftResolved($pattern, '2026-11-09'))->toBe([[1, '09:00:00', '17:00:00']]);
});

it('keeps ends_on inclusive and answers nothing after it', function () {
    $pattern = resolverPattern('2026-11-02', 1, '2026-11-16');

    expect(shiftResolved($pattern, '2026-11-16'))->toBe([[1, '09:00:00', '17:00:00']])
        ->and(shiftResolved($pattern, '2026-11-18'))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-23'))->toBe([]);
});

it('a pattern of one day, ends_on on starts_on, holds that day alone', function () {
    $pattern = resolverPattern('2026-11-04', 1, '2026-11-04');

    expect(shiftResolved($pattern, '2026-11-04'))->toBe([[3, '10:00:00', '14:00:00'], [3, '15:00:00', '19:00:00']])
        ->and(shiftResolved($pattern, '2026-11-11'))->toBe([]);
});

it('applies a cadence of two on the week holding starts_on and every second week after, counting from that week\'s first day, not from starts_on', function () {
    $pattern = resolverPattern('2026-11-04', 2);

    expect(shiftResolved($pattern, '2026-11-08'))->toBe([[7, '12:00:00', '16:00:00']])
        ->and(shiftResolved($pattern, '2026-11-09'))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-11'))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-15'))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-16'))->toBe([[1, '09:00:00', '17:00:00']])
        ->and(shiftResolved($pattern, '2026-11-18'))->toBe([[3, '10:00:00', '14:00:00'], [3, '15:00:00', '19:00:00']])
        ->and(shiftResolved($pattern, '2026-11-30'))->toBe([[1, '09:00:00', '17:00:00']]);
});

it('applies a cadence of three on weeks zero, three and six, and four on zero and four', function () {
    $three = resolverPattern('2026-11-02', 3);
    $four = resolverPattern('2026-11-02', 4);

    expect(shiftResolved($three, '2026-11-02'))->not->toBe([])
        ->and(shiftResolved($three, '2026-11-09'))->toBe([])
        ->and(shiftResolved($three, '2026-11-16'))->toBe([])
        ->and(shiftResolved($three, '2026-11-23'))->not->toBe([])
        ->and(shiftResolved($three, '2026-12-14'))->not->toBe([])
        ->and(shiftResolved($four, '2026-11-23'))->toBe([])
        ->and(shiftResolved($four, '2026-11-30'))->not->toBe([]);
});

it('anchors the cadence on the tenant\'s week start: a Sunday is in week zero from a Monday start and in week one from a Sunday start', function () {
    $pattern = resolverPattern('2026-11-04', 2);

    expect(shiftResolved($pattern, '2026-11-08', 1))->toBe([[7, '12:00:00', '16:00:00']])
        ->and(shiftResolved($pattern, '2026-11-08', 7))->toBe([])
        ->and(shiftResolved($pattern, '2026-11-15', 7))->toBe([[7, '12:00:00', '16:00:00']])
        ->and(shiftResolved($pattern, '2026-11-01', 7))->toBe([]);
});

it('lets an override stand before starts_on, after ends_on and on a week the cadence skips', function () {
    $pattern = resolverPattern('2026-11-04', 2, '2026-11-30');
    $window = [[WallClockTime::of('09:00'), WallClockTime::of('10:00')]];

    expect(shiftResolved($pattern, '2026-11-02', 1, ShiftOverride::of('2026-11-02', $window)))->toBe([[1, '09:00:00', '10:00:00']])
        ->and(shiftResolved($pattern, '2026-12-07', 1, ShiftOverride::of('2026-12-07', $window)))->toBe([[1, '09:00:00', '10:00:00']])
        ->and(shiftResolved($pattern, '2026-11-09', 1, ShiftOverride::of('2026-11-09', $window)))->toBe([[1, '09:00:00', '10:00:00']]);
});

it('refuses a pattern whose cadence is not one to four weeks, or whose end comes before its start', function () {
    expect(fn () => resolverPattern('2026-11-02', 0))->toThrow(InvalidShiftPattern::class, 'A shift pattern repeats every 1 to 4 weeks, not every 0.')
        ->and(fn () => resolverPattern('2026-11-02', 5))->toThrow(InvalidShiftPattern::class, 'A shift pattern repeats every 1 to 4 weeks, not every 5.')
        ->and(fn () => resolverPattern('2026-11-02', 1, '2026-11-01'))->toThrow(InvalidShiftPattern::class, 'A shift pattern cannot end on 2026-11-01, before it starts on 2026-11-02.')
        ->and(fn () => resolverPattern('2026-11-31'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ShiftOverride::of('2026-11-02', [[WallClockTime::of('10:00'), WallClockTime::of('09:00')]]))->toThrow(InvalidArgumentException::class);
});

it('refuses a pattern window carrying validity dates, which a pattern\'s own dates replace', function () {
    expect(fn () => new ShiftPattern('2026-11-02', 1, null, [new AvailabilityRule(1, WallClockTime::of('09:00'), WallClockTime::of('17:00'), '2026-11-01')]))
        ->toThrow(InvalidShiftPattern::class, 'A shift pattern\'s window carries no validity dates; the pattern\'s own dates bound it.');
});
