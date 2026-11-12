<?php

use App\Domain\Enum\HoldClass;

it('spells the three hold classes as the column stores them', function () {
    expect(array_map(fn (HoldClass $class): string => $class->value, HoldClass::cases()))
        ->toBe(['checkout', 'approval', 'payment']);
});

it('is string-backed, so a row value round-trips', function () {
    expect(HoldClass::from('approval'))->toBe(HoldClass::Approval);
});

it('defaults {class} to a TTL of {minutes} minutes', function (HoldClass $class, int $minutes) {
    expect($class->defaultTtlMinutes())->toBe($minutes);
})->with([
    'checkout, fifteen minutes' => [HoldClass::Checkout, 15],
    'approval, forty-eight hours' => [HoldClass::Approval, 2880],
    'payment, twenty-four hours' => [HoldClass::Payment, 1440],
]);
