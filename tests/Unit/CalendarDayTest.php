<?php

use App\Domain\Availability\CalendarDay;

it('accepts a day spelled YYYY-MM-DD and hands it back', function () {
    expect(CalendarDay::spelled('2026-03-29'))->toBe('2026-03-29');
});

it('refuses a day that is not spelled YYYY-MM-DD or is not a date: {day}', function (string $day) {
    expect(fn () => CalendarDay::spelled($day))->toThrow(InvalidArgumentException::class, sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
    expect(fn () => CalendarDay::weekday($day))->toThrow(InvalidArgumentException::class, sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
})->with([
    'a month and day without padding' => ['2026-1-1'],
    'a word' => ['today'],
    'a date with a time' => ['2026-10-01 10:00'],
    'the thirtieth of February' => ['2026-02-30'],
    'an empty string' => [''],
]);

it('answers the ISO weekday, Monday one to Sunday seven', function () {
    expect(CalendarDay::weekday('2026-03-23'))->toBe(1)
        ->and(CalendarDay::weekday('2026-03-28'))->toBe(6)
        ->and(CalendarDay::weekday('2026-03-29'))->toBe(7)
        ->and(CalendarDay::weekday('2026-10-25'))->toBe(7);
});

it('orders two days as the calendar does', function () {
    expect(CalendarDay::compare('2026-03-01', '2026-03-29'))->toBe(-1)
        ->and(CalendarDay::compare('2026-03-29', '2026-03-29'))->toBe(0)
        ->and(CalendarDay::compare('2026-12-31', '2027-01-01'))->toBe(-1)
        ->and(CalendarDay::compare('2027-01-01', '2026-12-31'))->toBe(1);
});

it('adds a number of days, across a month end, a year end and a clock change, and takes them away again', function () {
    expect(CalendarDay::plusDays('2026-02-27', 2))->toBe('2026-03-01')
        ->and(CalendarDay::plusDays('2026-12-31', 1))->toBe('2027-01-01')
        ->and(CalendarDay::plusDays('2026-03-28', 1))->toBe('2026-03-29')
        ->and(CalendarDay::plusDays('2026-03-28', 60))->toBe('2026-05-27')
        ->and(CalendarDay::plusDays('2026-03-28', 0))->toBe('2026-03-28')
        ->and(CalendarDay::plusDays('2026-03-01', -1))->toBe('2026-02-28');
});

it('refuses to add days to something that is not a day', function () {
    expect(fn () => CalendarDay::plusDays('today', 1))->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "today".');
});

it('counts the days from one day to another, negative when the second comes first, across a clock change and a year end', function () {
    expect(CalendarDay::daysBetween('2026-04-06', '2026-04-13'))->toBe(7)
        ->and(CalendarDay::daysBetween('2026-03-28', '2026-03-30'))->toBe(2)
        ->and(CalendarDay::daysBetween('2026-12-31', '2027-01-01'))->toBe(1)
        ->and(CalendarDay::daysBetween('2026-04-06', '2026-04-06'))->toBe(0)
        ->and(CalendarDay::daysBetween('2026-04-13', '2026-04-06'))->toBe(-7)
        ->and(fn () => CalendarDay::daysBetween('2026-04-06', 'soon'))->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "soon".');
});

it('answers the first day of the week holding a day, on the week start given, the rule CalendarWindow::weekOf opens the calendar on', function () {
    expect(CalendarDay::weekStart('2026-11-04', 1))->toBe('2026-11-02')
        ->and(CalendarDay::weekStart('2026-11-02', 1))->toBe('2026-11-02')
        ->and(CalendarDay::weekStart('2026-11-01', 1))->toBe('2026-10-26')
        ->and(CalendarDay::weekStart('2026-11-04', 7))->toBe('2026-11-01')
        ->and(CalendarDay::weekStart('2026-11-01', 7))->toBe('2026-11-01')
        ->and(CalendarDay::weekStart('2026-10-31', 6))->toBe('2026-10-31')
        ->and(CalendarDay::weekStart('2026-10-30', 6))->toBe('2026-10-24')
        ->and(CalendarDay::weekStart('2027-01-01', 1))->toBe('2026-12-28');
});

it('refuses a week start that is not an ISO weekday: {startOfWeek}', function (int $startOfWeek) {
    expect(fn () => CalendarDay::weekStart('2026-11-04', $startOfWeek))->toThrow(InvalidArgumentException::class, sprintf('A week starts on an ISO weekday, 1 to 7, not %d.', $startOfWeek));
})->with([0, 8, -1]);

it('counts the weeks from the week holding one day to the week holding another, on the week start given, across a clock change and a year end', function () {
    expect(CalendarDay::weeksBetween('2026-11-02', '2026-11-08', 1))->toBe(0)
        ->and(CalendarDay::weeksBetween('2026-11-08', '2026-11-09', 1))->toBe(1)
        ->and(CalendarDay::weeksBetween('2026-11-08', '2026-11-09', 7))->toBe(0)
        ->and(CalendarDay::weeksBetween('2026-11-07', '2026-11-08', 7))->toBe(1)
        ->and(CalendarDay::weeksBetween('2026-10-21', '2026-11-04', 1))->toBe(2)
        ->and(CalendarDay::weeksBetween('2026-12-30', '2027-01-13', 1))->toBe(2)
        ->and(CalendarDay::weeksBetween('2026-11-04', '2026-10-21', 1))->toBe(-2);
});
