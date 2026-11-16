<?php

use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\Overridable;
use App\Http\CrossingReasons;

/*
 * Why a member has no hours on the date a move would land on, as the warning's facts say it. The
 * weekday is the member's own day off only where their usual week never holds it, and the
 * business's only where no member's usual week does. In every other case the date alone is off:
 * an override took it, the pattern works the weekday and skips this week, or no pattern holds
 * the date, none of which says anything of the weekday.
 *
 * Elena is member 2 and Nikos member 3, the two whose weeks make the business's. The move lands
 * on Wednesday 25 November 2026 at 11:00 in Athens, UTC+2.
 */

const CROSSING_DATE = '2026-11-25';

const CROSSING_NINE_TO_FIVE = [['start' => '09:00', 'end' => '17:00']];

/**
 * One member's day as the hours are read for a warning.
 *
 * @param  list<array{start: string, end: string}>  $windows
 * @param  list<array{start: string, end: string}>|null  $usual
 * @return array<string, array{windows: list<array{start: string, end: string}>, overridden: bool, usual: list<array{start: string, end: string}>|null}>
 */
function crossingDay(?array $usual, array $windows = [], bool $overridden = false): array
{
    return [CROSSING_DATE => ['windows' => $windows, 'overridden' => $overridden, 'usual' => $usual]];
}

/**
 * Elena's crossing of working hours on the date, with the days given, as the wire carries its
 * hours and why there are none.
 *
 * @param  array<int, array<string, array{windows: list<array{start: string, end: string}>, overridden: bool, usual: list<array{start: string, end: string}>|null}>>  $days
 * @param  list<int>  $weekly
 * @return array{hours: list<array{start: string, end: string}>, day_off: string|null}
 */
function crossingHours(array $days, array $weekly = [2, 3]): array
{
    $athens = new DateTimeZone('Europe/Athens');
    $crossing = new MaskCrossing(0, 2, Overridable::Hours, new DateTimeImmutable(CROSSING_DATE.' 11:00', $athens), new DateTimeImmutable(CROSSING_DATE.' 11:45', $athens));
    $reasons = CrossingReasons::reasonsOf($crossing, $athens, $days, $weekly, []);

    return ['hours' => $reasons['hours'], 'day_off' => $reasons['day_off']];
}

it('names the hours and no day off where the member has windows that date', function () {
    expect(crossingHours([2 => crossingDay(CROSSING_NINE_TO_FIVE, CROSSING_NINE_TO_FIVE), 3 => crossingDay([])]))
        ->toBe(['hours' => CROSSING_NINE_TO_FIVE, 'day_off' => null]);
});

it('says the weekday is the member\'s own day off where their usual week never holds it and another member\'s does, whether or not that member is in that week', function () {
    expect(crossingHours([2 => crossingDay([]), 3 => crossingDay(CROSSING_NINE_TO_FIVE, CROSSING_NINE_TO_FIVE)])['day_off'])->toBe('weekday')
        ->and(crossingHours([2 => crossingDay([]), 3 => crossingDay(CROSSING_NINE_TO_FIVE)])['day_off'])->toBe('weekday');
});

it('says the business is closed that weekday where no member\'s usual week holds it, a member with no pattern holding the date adding nothing', function () {
    expect(crossingHours([2 => crossingDay([]), 3 => crossingDay([])])['day_off'])->toBe('business')
        ->and(crossingHours([2 => crossingDay([]), 3 => crossingDay(null)])['day_off'])->toBe('business')
        ->and(crossingHours([2 => crossingDay([])], [2])['day_off'])->toBe('business');
});

it('says the date is off where an override took it, whatever the usual week says of the weekday', function () {
    expect(crossingHours([2 => crossingDay(CROSSING_NINE_TO_FIVE, [], true), 3 => crossingDay(CROSSING_NINE_TO_FIVE, CROSSING_NINE_TO_FIVE)])['day_off'])->toBe('date')
        ->and(crossingHours([2 => crossingDay([], [], true), 3 => crossingDay([])])['day_off'])->toBe('date');
});

it('says the date is off, never the weekday, where the member\'s usual week holds the weekday and the pattern skips this week, with another member working or nobody', function () {
    expect(crossingHours([2 => crossingDay(CROSSING_NINE_TO_FIVE), 3 => crossingDay(CROSSING_NINE_TO_FIVE, CROSSING_NINE_TO_FIVE)])['day_off'])->toBe('date')
        ->and(crossingHours([2 => crossingDay(CROSSING_NINE_TO_FIVE), 3 => crossingDay([])])['day_off'])->toBe('date')
        ->and(crossingHours([2 => crossingDay(CROSSING_NINE_TO_FIVE)], [2])['day_off'])->toBe('date');
});

it('says the date is off where no pattern of the member holds the date, or nothing was read of their day', function () {
    expect(crossingHours([2 => crossingDay(null), 3 => crossingDay(CROSSING_NINE_TO_FIVE, CROSSING_NINE_TO_FIVE)])['day_off'])->toBe('date')
        ->and(crossingHours([2 => crossingDay(null), 3 => crossingDay([])])['day_off'])->toBe('date')
        ->and(crossingHours([3 => crossingDay([])])['day_off'])->toBe('date');
});
