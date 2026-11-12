<?php

use App\Billing\ProviderStatus;
use App\Billing\SubscriptionState;

/*
 * The one map from the billing provider's status strings to the states the tenant row caches. trial
 * is not among them: it is the platform's own clock, written by provisioning alone, and no provider
 * status maps to it (4.1). A null answer leaves the stored state where it is: past_due is the
 * provider's word for a charge that failed, and the grace window it opens belongs to the
 * failed-charge event and the lapse, never to a snapshot.
 */

it('maps every status the provider names to the state a snapshot writes', function (string $status, ?SubscriptionState $state) {
    expect(ProviderStatus::from($status)->state())->toBe($state);
})->with([
    'active' => ['active', SubscriptionState::Active],
    'past_due, owned by the charge events' => ['past_due', null],
    'unpaid' => ['unpaid', SubscriptionState::PastDue],
    'paused' => ['paused', SubscriptionState::PastDue],
    'incomplete' => ['incomplete', SubscriptionState::Incomplete],
    'incomplete_expired' => ['incomplete_expired', SubscriptionState::Canceled],
    'canceled' => ['canceled', SubscriptionState::Canceled],
]);

it('names seven statuses, so an eighth is a decision somebody writes', function () {
    expect(array_map(fn (ProviderStatus $status): string => $status->value, ProviderStatus::cases()))
        ->toBe(['active', 'past_due', 'unpaid', 'paused', 'incomplete', 'incomplete_expired', 'canceled']);
});

it('does not recognise trialing, the trial being the platform clock with no provider object', function (string $status) {
    expect(ProviderStatus::tryFrom($status))->toBeNull();
})->with(['trialing']);

it('maps no provider status to trial, so the sync never writes it', function () {
    $states = array_map(fn (ProviderStatus $status): ?SubscriptionState => $status->state(), ProviderStatus::cases());

    expect($states)->not->toContain(SubscriptionState::Trial);
});
