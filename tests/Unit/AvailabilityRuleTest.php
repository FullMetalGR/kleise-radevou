<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\InvalidAvailabilityRule;
use App\Domain\Availability\WallClockTime;

function weeklyRule(int $weekday, string $start, string $end, ?string $validFrom = null, ?string $validTo = null): AvailabilityRule
{
    return new AvailabilityRule($weekday, WallClockTime::of($start), WallClockTime::of($end), $validFrom, $validTo);
}

it('is a weekday, a wall-clock window and an optional validity range', function () {
    $rule = weeklyRule(1, '09:00', '17:00', '2026-01-01', '2026-06-30');
    $open = weeklyRule(7, '10:00', '14:00');

    expect($rule->weekday)->toBe(1)
        ->and($rule->start->format())->toBe('09:00:00')
        ->and($rule->end->format())->toBe('17:00:00')
        ->and($rule->validFrom)->toBe('2026-01-01')
        ->and($rule->validTo)->toBe('2026-06-30')
        ->and($open->validFrom)->toBeNull()
        ->and($open->validTo)->toBeNull();
});

it('applies on its weekday and on no other', function () {
    $sunday = weeklyRule(7, '09:00', '17:00');

    expect($sunday->appliesOn('2026-03-29'))->toBeTrue()
        ->and($sunday->appliesOn('2026-03-28'))->toBeFalse()
        ->and($sunday->appliesOn('2026-03-30'))->toBeFalse()
        ->and($sunday->appliesOn('2026-04-05'))->toBeTrue();
});

it('applies only when the date lies inside its validity range, both ends inclusive', function () {
    $bounded = weeklyRule(7, '09:00', '17:00', '2026-03-29', '2026-04-05');

    expect($bounded->appliesOn('2026-03-22'))->toBeFalse()
        ->and($bounded->appliesOn('2026-03-29'))->toBeTrue()
        ->and($bounded->appliesOn('2026-04-05'))->toBeTrue()
        ->and($bounded->appliesOn('2026-04-12'))->toBeFalse();
});

it('treats a null end of the validity range as open on that side', function () {
    $from = weeklyRule(7, '09:00', '17:00', '2026-03-29', null);
    $until = weeklyRule(7, '09:00', '17:00', null, '2026-03-29');

    expect($from->appliesOn('2026-03-22'))->toBeFalse()
        ->and($from->appliesOn('2026-03-29'))->toBeTrue()
        ->and($from->appliesOn('2036-03-30'))->toBeTrue()
        ->and($until->appliesOn('2016-03-27'))->toBeTrue()
        ->and($until->appliesOn('2026-03-29'))->toBeTrue()
        ->and($until->appliesOn('2026-04-05'))->toBeFalse();
});

it('refuses a rule whose start is not before its end, with a typed error naming both', function (string $start, string $end) {
    try {
        weeklyRule(1, $start, $end);
    } catch (InvalidAvailabilityRule $e) {
        expect($e->getMessage())->toBe(sprintf('An availability rule opens before it closes, not from %s to %s.', WallClockTime::of($start)->format(), WallClockTime::of($end)->format()));

        return;
    }

    $this->fail('No InvalidAvailabilityRule was thrown.');
})->with([
    'inverted' => ['17:00', '09:00'],
    'empty' => ['09:00', '09:00'],
    'empty to the second' => ['09:00:00', '09:00'],
]);

it('refuses a weekday outside ISO one to seven: {weekday}', function (int $weekday) {
    expect(fn () => weeklyRule($weekday, '09:00', '17:00'))
        ->toThrow(InvalidAvailabilityRule::class, sprintf('A weekday is ISO 1 to 7, not %d.', $weekday));
})->with(['zero' => [0], 'eight' => [8], 'negative' => [-1]]);

it('refuses a validity range that ends before it starts', function () {
    expect(fn () => weeklyRule(1, '09:00', '17:00', '2026-04-05', '2026-03-29'))
        ->toThrow(InvalidAvailabilityRule::class, 'A validity range starts no later than it ends, not from 2026-04-05 to 2026-03-29.');
});

it('refuses a validity date that is not spelled YYYY-MM-DD', function () {
    expect(fn () => weeklyRule(1, '09:00', '17:00', 'tomorrow', null))->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "tomorrow".');
    expect(fn () => weeklyRule(1, '09:00', '17:00', null, '2026-02-30'))->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "2026-02-30".');
});

it('accepts a window that closes at the end of the day', function () {
    expect(weeklyRule(1, '20:00', '24:00')->end->format())->toBe('24:00:00');
});
