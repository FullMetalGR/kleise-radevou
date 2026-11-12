<?php

use App\Domain\Booking\LockKey;

it('is a staff member and a calendar day', function () {
    $key = new LockKey(7, '2026-10-01');

    expect($key->staffId)->toBe(7)
        ->and($key->day)->toBe('2026-10-01')
        ->and($key->toString())->toBe('7:2026-10-01');
});

it('refuses a day that is not spelled YYYY-MM-DD or is not a date: {day}', function (string $day) {
    expect(fn () => new LockKey(1, $day))->toThrow(InvalidArgumentException::class);
})->with([
    'a month and day without padding' => ['2026-1-1'],
    'a word' => ['today'],
    'a date with a time' => ['2026-10-01 10:00'],
    'the thirtieth of February' => ['2026-02-30'],
    'a thirteenth month' => ['2026-13-01'],
    'an empty string' => [''],
]);

it('refuses a staff id below one', function (int $staffId) {
    expect(fn () => new LockKey($staffId, '2026-10-01'))->toThrow(InvalidArgumentException::class);
})->with(['zero' => [0], 'negative' => [-3]]);

it('orders by staff member first and by day second', function () {
    $early = new LockKey(2, '2026-10-05');
    $late = new LockKey(9, '2026-10-01');
    $sameStaffLater = new LockKey(2, '2026-10-06');

    expect($early->compare($late))->toBeLessThan(0)
        ->and($late->compare($early))->toBeGreaterThan(0)
        ->and($early->compare($sameStaffLater))->toBeLessThan(0)
        ->and($early->compare(new LockKey(2, '2026-10-05')))->toBe(0);
});

it('is equal to another key naming the same staff-day and to nothing else', function () {
    $key = new LockKey(2, '2026-10-05');

    expect($key->equals(new LockKey(2, '2026-10-05')))->toBeTrue()
        ->and($key->equals(new LockKey(3, '2026-10-05')))->toBeFalse()
        ->and($key->equals(new LockKey(2, '2026-10-06')))->toBeFalse();
});
