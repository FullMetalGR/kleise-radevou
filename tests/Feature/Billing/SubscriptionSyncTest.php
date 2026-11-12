<?php

use App\Application\Clock;
use App\Billing\CatalogueUnreadable;
use App\Billing\Entitlements;
use App\Billing\MalformedSnapshot;
use App\Billing\SubscriptionSync;
use App\Billing\SyncOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\SeamLog;

pest()->use(RefreshDatabase::class);

/*
 * The subscription sync: the one writer of the tenant row's billing columns and of the
 * subscriptions row. A snapshot resolves its items to features through the catalogue and
 * writes them beside the raw identifiers, the provider's status maps to a state the row
 * caches, never trial, and the narrowing record keeps its four cases. The invoice events open
 * and close the grace window, which a snapshot never closes on its own.
 *
 * The rows are central rows alone: the sync reads and writes no tenant schema, so no tenant is
 * provisioned here. The prices are the ones phpunit.xml puts in the environment.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00'));
    $this->log = new SeamLog;

    billingTenant('luna');
    billingTenant('pier');
});

it('records the customer signup created, the subscription unnamed until the first snapshot', function () {
    expect(subscriptionColumns())->toBe([
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => null,
        'status' => null,
        'current_period_end' => null,
        'items' => '[]',
    ]);
});

it('writes the subscriptions row and the tenant\'s resolved set, raw identifiers and state from a snapshot', function () {
    $outcome = syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    expect($outcome)->toBe(SyncOutcome::Applied)
        ->and(billingColumns())->toBe(['entitlements' => '{staff}', 'narrowed' => '{}', 'plan_ref' => 'price_staff_test', 'subscription_state' => 'active', 'grace_ends_at' => null])
        ->and(subscriptionColumns())->toBe([
            'provider_customer_id' => 'cus_luna',
            'provider_subscription_id' => 'sub_luna',
            'status' => 'active',
            'current_period_end' => '2026-10-29 09:00',
            'items' => '[{"price": "price_staff_test", "quantity": 1}]',
        ])
        ->and(billingColumns('pier'))->toBe(['entitlements' => '{}', 'narrowed' => '{}', 'plan_ref' => null, 'subscription_state' => 'active', 'grace_ends_at' => null]);
});

it('resolves the items through the catalogue, an identifier it does not know buying the floor', function (array $prices, string $entitlements, ?string $planRef) {
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', $prices));

    expect(billingColumns())->toMatchArray(['entitlements' => $entitlements, 'plan_ref' => $planRef]);
})->with([
    'the staff plan' => [['price_staff_test'], '{staff}', 'price_staff_test'],
    'the floor plan' => [['price_floor_test'], '{}', 'price_floor_test'],
    'an unknown identifier' => [['price_nobody_deployed'], '{}', 'price_nobody_deployed'],
    'the staff plan beside an unknown one' => [['price_nobody_deployed', 'price_staff_test'], '{staff}', 'price_nobody_deployed,price_staff_test'],
    'no items at all' => [[], '{}', null],
]);

it('records what a resolve takes away', function () {
    billingSet(['entitlements' => '{staff}']);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));

    expect(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{staff}']);
});

it('clears the record when a resolve gives something back', function () {
    billingSet(['entitlements' => '{}', 'narrowed' => '{staff}']);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    expect(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'narrowed' => '{}']);
});

it('keeps the record when a resolve changes nothing, a different price resolving to the same set included', function (string $entitlements, string $storedPrice, string $price) {
    config(['billing.plans.staff_yearly' => ['name' => 'Team yearly', 'price' => 'price_staff_yearly_test', 'features' => ['staff'], 'display' => ['amount' => 39000, 'currency' => 'EUR']]]);
    billingSet(['entitlements' => $entitlements, 'plan_ref' => $storedPrice, 'narrowed' => '{staff}']);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', [$price]));

    expect(billingColumns())->toMatchArray(['entitlements' => $entitlements, 'narrowed' => '{staff}', 'plan_ref' => $price]);
})->with([
    'the floor again' => ['{}', 'price_floor_test', 'price_floor_test'],
    'staff again' => ['{staff}', 'price_staff_test', 'price_staff_test'],
    'the stored floor price, then an unknown one resolving to the floor' => ['{}', 'price_floor_test', 'price_nobody_deployed'],
    'the stored staff price, then another plan granting staff' => ['{staff}', 'price_staff_test', 'price_staff_yearly_test'],
]);

/*
 * The fourth case: the owner's own plan change tells the sync the set the owner chose, and what a
 * resolve takes that the chosen set lacks is what the owner asked to remove. The record describes
 * what the subscription did without being asked, so that is not recorded; the webhook, handing no
 * chosen set, records as before.
 */

it('records what a resolve takes away unless the owner asked to remove it', function (?Entitlements $chosen, string $narrowed) {
    billingSet(['entitlements' => '{staff}', 'plan_ref' => 'price_staff_test']);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']), chosen: $chosen);

    expect(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => $narrowed, 'plan_ref' => 'price_floor_test']);
})->with([
    'the owner chose the floor' => [Entitlements::none(), '{}'],
    'nobody here asked, as the webhook hands it' => [null, '{staff}'],
]);

it('clears the record when the owner\'s own change gives something back', function () {
    billingSet(['entitlements' => '{}', 'narrowed' => '{staff}']);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']), chosen: Entitlements::fromSlugs(['staff']));

    expect(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'narrowed' => '{}']);
});

it('still records what the owner\'s change took that the plan the owner chose carries', function () {
    billingSet(['entitlements' => '{staff}', 'plan_ref' => 'price_staff_test']);

    // The owner chose another plan granting staff, and the provider answered with the floor.
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']), chosen: Entitlements::fromSlugs(['staff']));

    expect(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{staff}']);
});

it('keeps a record of what the plan the owner chose carries when the owner\'s change takes nothing and gives nothing back', function () {
    billingSet(['entitlements' => '{}', 'plan_ref' => 'price_floor_test', 'narrowed' => '{staff}']);

    // The owner's Add Staff, answered by a snapshot still lacking staff.
    expect(syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']), chosen: Entitlements::fromSlugs(['staff'])))->toBe(SyncOutcome::Applied)
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{staff}', 'plan_ref' => 'price_floor_test']);
});

it('records nothing on the re-read of the owner\'s removal whose first write met a moved revision', function (Closure $meanwhile, string $recordedMeanwhile) {
    billingSet(['entitlements' => '{staff}', 'plan_ref' => 'price_staff_test']);
    $floor = billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']);
    billingFake()->script($floor);
    $read = subscriptionRevision();

    $meanwhile();

    expect(billingColumns()['narrowed'])->toBe($recordedMeanwhile)
        ->and(syncWith()->apply($floor, $read, Entitlements::none()))->toBe(SyncOutcome::Stale)
        ->and(syncWith()->refresh('sub_luna', subscriptionRevision(), Entitlements::none()))->toBe(SyncOutcome::Applied)
        ->and(billingColumns())->toMatchArray(['entitlements' => '{}', 'narrowed' => '{}', 'plan_ref' => 'price_floor_test']);
})->with([
    'a write that changed nothing' => [fn () => syncWith()->chargeSucceeded('cus_luna'), '{}'],
    'the webhook for the same change, landing first and recording what it took' => [fn () => syncWith()->refresh('sub_luna'), '{staff}'],
]);

it('changes nothing on a snapshot it cannot resolve and writes one log line naming the tenant and the cause', function (Closure $breaks, array $prices, string $exception, string $cause) {
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    billingSet(['narrowed' => '{staff}']);
    $tenantBefore = billingColumns();
    $subscriptionBefore = subscriptionColumns();
    $breaks();

    $outcome = syncWith($this->log)->apply(billingSnapshot('sub_luna', 'cus_luna', 'canceled', $prices));

    expect($outcome)->toBe(SyncOutcome::Unresolved)
        ->and(billingColumns())->toBe($tenantBefore)
        ->and(subscriptionColumns())->toBe($subscriptionBefore)
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => 'The subscription sync left a tenant as it was',
            'context' => ['tenant' => 'luna', 'exception' => $exception, 'message' => $cause],
        ]]);
})->with([
    'a malformed item list' => [fn () => null, ['price_floor_test', ''], MalformedSnapshot::class, 'Line 2 of the subscription names no price or a quantity below nought.'],
    'a catalogue read that throws' => [fn () => config(['billing.plans' => 'none']), ['price_floor_test'], CatalogueUnreadable::class, 'The billing catalogue holds no list of plans.'],
    'a plan whose price the environment leaves blank' => [fn () => config(['billing.plans.staff.price' => '']), ['price_floor_test'], CatalogueUnreadable::class, 'The billing plan "staff" is sold under no price identifier: billing.plans.staff.price is unset or blank in the environment.'],
    'a plan whose price the environment leaves unset' => [fn () => config(['billing.plans.staff.price' => null]), ['price_floor_test'], CatalogueUnreadable::class, 'The billing plan "staff" is sold under no price identifier: billing.plans.staff.price is unset or blank in the environment.'],
]);

it('maps the provider\'s status to the state it writes', function (string $status, string $state) {
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_staff_test']));

    expect(billingColumns()['subscription_state'])->toBe($state);
})->with([
    'active' => ['active', 'active'],
    'a deleted subscription' => ['canceled', 'canceled'],
    'an incomplete one' => ['incomplete', 'incomplete'],
    'past_due, whose window the failed charge opens, keeps the stored state' => ['past_due', 'active'],
]);

it('keeps the stored state on a status this build does not recognise, logging it, and still resolves the items', function (string $status) {
    billingSet(['subscription_state' => 'incomplete']);

    syncWith($this->log)->apply(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_staff_test']));

    expect(billingColumns())->toMatchArray(['subscription_state' => 'incomplete', 'entitlements' => '{staff}'])
        ->and(subscriptionColumns()['status'])->toBe($status)
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => 'The billing provider reported a subscription status this build does not know; the stored state is kept',
            'context' => ['tenant' => 'luna', 'status' => $status],
        ]]);
})->with([
    'a status nobody has heard of' => ['on_holiday'],
    'trialing, the trial being the platform clock' => ['trialing'],
]);

/**
 * The trial's end as UTC text, or null once cleared.
 */
function trialEndOf(string $slug = 'luna'): ?string
{
    $end = DB::scalar("select to_char(trial_ends_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') from tenants where slug = ?", [$slug]);

    return is_string($end) ? $end : null;
}

it('lands the first subscription on a trial row in the state its status maps to, clearing the trial end in the same write', function (string $status, string $state) {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);

    expect(syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_staff_test'])))->toBe(SyncOutcome::Applied)
        ->and(billingColumns())->toMatchArray(['subscription_state' => $state, 'entitlements' => '{staff}', 'plan_ref' => 'price_staff_test'])
        ->and(trialEndOf())->toBeNull();
})->with([
    'active' => ['active', 'active'],
    'incomplete' => ['incomplete', 'incomplete'],
    'a deleted subscription' => ['canceled', 'canceled'],
]);

it('records nothing narrowed when the first subscription on a trial row buys less than the trial carried, the plan being the owner\'s choice', function () {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));

    expect(billingColumns())->toMatchArray(['subscription_state' => 'active', 'entitlements' => '{}', 'narrowed' => '{}'])
        ->and(trialEndOf())->toBeNull();
});

it('keeps a trial, its end, its full set and its empty plan on a status the sync keeps as stored, so the lapse still ends it', function (string $status) {
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => '2026-10-08 09:00:00+00']);

    syncWith($this->log)->apply(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_floor_test']));

    expect(billingColumns())->toMatchArray(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'plan_ref' => null, 'narrowed' => '{}'])
        ->and(trialEndOf())->toBe('2026-10-08 09:00');
})->with([
    'trialing, not recognised' => ['trialing'],
    'past_due, left to the charge events' => ['past_due'],
    'a status this build does not know' => ['on_hold'],
]);

it('never writes trial on a later snapshot, whatever its status', function (string $status, string $state) {
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_staff_test']));

    expect(billingColumns()['subscription_state'])->toBe($state)
        ->and(trialEndOf())->toBeNull();
})->with([
    'active' => ['active', 'active'],
    'unpaid' => ['unpaid', 'past_due'],
    'trialing, kept as stored' => ['trialing', 'active'],
]);

it('opens a grace window on a failed charge, the configured days ahead', function (int $days, string $ends) {
    config(['billing.grace_days' => $days]);

    expect(syncWith()->chargeFailed('cus_luna'))->toBe(SyncOutcome::Applied)
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'grace', 'grace_ends_at' => $ends]);
})->with([
    'seven days' => [7, '2026-10-08 09:00'],
    'three days' => [3, '2026-10-04 09:00'],
]);

it('changes nothing on a failed charge when the grace length cannot be read, and logs it', function () {
    config(['billing.grace_days' => 'a week']);
    $before = billingColumns();

    expect(syncWith($this->log)->chargeFailed('cus_luna'))->toBe(SyncOutcome::Unresolved)
        ->and(billingColumns())->toBe($before)
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => 'The subscription sync left a tenant as it was',
            'context' => ['tenant' => 'luna', 'exception' => CatalogueUnreadable::class, 'message' => 'The billing grace length is not a whole number of days above nought.'],
        ]]);
});

it('never extends an open window on a second failed charge', function () {
    syncWith()->chargeFailed('cus_luna');
    app()->instance(Clock::class, FixedClock::at('2026-10-04 12:00'));

    syncWith()->chargeFailed('cus_luna');

    expect(billingColumns())->toMatchArray(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-08 09:00']);
});

it('closes the window on a succeeded charge, writing active and clearing the date', function (string $from) {
    syncWith()->chargeFailed('cus_luna');
    billingSet(['subscription_state' => $from, 'grace_ends_at' => $from === 'grace' ? '2026-10-08 09:00+00' : null]);

    expect(syncWith()->chargeSucceeded('cus_luna'))->toBe(SyncOutcome::Applied)
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'active', 'grace_ends_at' => null]);
})->with(['grace', 'past_due', 'incomplete']);

it('keeps grace on a snapshot arriving inside the window unless it says the subscription was deleted', function (string $status) {
    syncWith()->chargeFailed('cus_luna');

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', $status, ['price_staff_test']));

    expect(billingColumns())->toMatchArray(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-08 09:00', 'entitlements' => '{staff}']);
})->with(['active', 'past_due', 'incomplete', 'unpaid']);

it('writes canceled on a deleted subscription whatever the stored state, and clears an open window', function (string $state, ?string $graceEndsAt) {
    billingSet(['subscription_state' => $state, 'grace_ends_at' => $graceEndsAt]);

    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'canceled', ['price_staff_test']));

    expect(billingColumns())->toMatchArray(['subscription_state' => 'canceled', 'grace_ends_at' => null]);
})->with([
    'inside the grace window' => ['grace', '2026-10-08 09:00+00'],
    'active' => ['active', null],
    'lapsed' => ['past_due', null],
    'incomplete' => ['incomplete', null],
]);

it('opens no window on a failed charge for a tenant that is not active', function (string $state) {
    billingSet(['subscription_state' => $state]);

    syncWith()->chargeFailed('cus_luna');

    expect(billingColumns())->toMatchArray(['subscription_state' => $state, 'grace_ends_at' => null]);
})->with([
    'after the lapse' => ['past_due'],
    'on a deleted subscription' => ['canceled'],
    'on an incomplete one' => ['incomplete'],
]);

it('revives no subscription deleted inside the window on a later succeeded charge', function () {
    syncWith()->chargeFailed('cus_luna');
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'canceled', ['price_staff_test']));

    syncWith()->chargeSucceeded('cus_luna');

    expect(billingColumns())->toMatchArray(['subscription_state' => 'canceled', 'grace_ends_at' => null]);
});

it('writes nothing for a customer no tenant answers to, and logs it', function (Closure $event) {
    $before = [billingColumns(), billingColumns('pier')];

    expect($event(syncWith($this->log)))->toBe(SyncOutcome::UnknownTenant)
        ->and([billingColumns(), billingColumns('pier')])->toBe($before)
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => 'The billing provider named a customer no tenant answers to; nothing was written',
            'context' => ['customer' => 'cus_nobody'],
        ]]);
})->with([
    'a snapshot' => [fn (SubscriptionSync $sync) => $sync->apply(billingSnapshot('sub_x', 'cus_nobody', 'active', ['price_staff_test']))],
    'a failed charge' => [fn (SubscriptionSync $sync) => $sync->chargeFailed('cus_nobody')],
    'a succeeded charge' => [fn (SubscriptionSync $sync) => $sync->chargeSucceeded('cus_nobody')],
]);

it('re-reads a subscription through the port and applies what the provider answers', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    expect(syncWith()->refresh('sub_luna'))->toBe(SyncOutcome::Applied)
        ->and(billingFake()->asked)->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'plan_ref' => 'price_staff_test']);
});
