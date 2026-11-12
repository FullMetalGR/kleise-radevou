<?php

use App\Billing\SubscriptionState;

it('is active for exactly active, grace and trial', function (string $state, bool $active) {
    expect(SubscriptionState::from($state)->isActive())->toBe($active);
})->with([
    ['active', true],
    ['grace', true],
    ['past_due', false],
    ['canceled', false],
    ['incomplete', false],
    ['trial', true],
]);

it('knows the six states of the tenants check and no seventh', function () {
    expect(array_map(fn (SubscriptionState $s): string => $s->value, SubscriptionState::cases()))
        ->toBe(['active', 'grace', 'past_due', 'canceled', 'incomplete', 'trial']);
});
