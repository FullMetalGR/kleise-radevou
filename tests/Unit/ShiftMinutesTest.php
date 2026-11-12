<?php

use App\Domain\Availability\ShiftMinutes;

/*
 * The minutes of a member's day: the windows' length less what the closures cover, an all-day
 * closure covering everything, two closures over one stretch taking it once.
 */

it('counts the windows of a day', function () {
    expect(ShiftMinutes::ofDay([['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:30']], []))->toBe(510)
        ->and(ShiftMinutes::ofDay([], []))->toBe(0)
        ->and(ShiftMinutes::ofDay([['start' => '18:00', 'end' => '24:00']], []))->toBe(360);
});

it('takes a partial closure out of the windows it reaches and nothing out of those it misses', function () {
    $windows = [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '18:00']];

    expect(ShiftMinutes::ofDay($windows, [['start' => '12:00', 'end' => '15:00']]))->toBe(360)
        ->and(ShiftMinutes::ofDay($windows, [['start' => '13:00', 'end' => '14:00']]))->toBe(480)
        ->and(ShiftMinutes::ofDay($windows, [['start' => '14:00:00', 'end' => '16:00:00']]))->toBe(360);
});

it('takes the whole day for a closure with no window, and an overlap of two closures once', function () {
    $windows = [['start' => '09:00', 'end' => '17:00']];

    expect(ShiftMinutes::ofDay($windows, [['start' => null, 'end' => null]]))->toBe(0)
        ->and(ShiftMinutes::ofDay($windows, [['start' => '10:00', 'end' => '12:00'], ['start' => '11:00', 'end' => '13:00']]))->toBe(300)
        ->and(ShiftMinutes::ofDay($windows, [['start' => '10:00', 'end' => '12:00'], ['start' => '10:30', 'end' => '11:00']]))->toBe(360);
});

it('counts a repeating week\'s windows with no closure', function () {
    expect(ShiftMinutes::ofWindows([['start' => '10:00', 'end' => '19:00'], ['start' => '10:00', 'end' => '16:00']]))->toBe(900);
});
