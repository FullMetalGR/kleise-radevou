<?php

use App\Billing\Entitlements;
use App\Billing\PlanCatalogue;
use App\Billing\PlanChange;
use App\Billing\PlanChangeRefused;
use App\Billing\SubscriptionState;
use App\Platform\TenantPlan;
use App\Tenancy\TenantStatus;

/*
 * The plan a console row lists: derived from the tenant row and its subscriptions row or none,
 * never stored. Trial while the subscription state is trial; free while no subscriptions row
 * exists and no trial end is set, the shape tenant:provision writes; otherwise the catalogue plan
 * the stored price reference matches, as the Billing screen reads it, and unlisted where none
 * does. A provisioning row lists no plan. The catalogue is built here from a plain array, the
 * shape config/billing.php holds, so the suite boots nothing. The free shape's predicate, which
 * the console's Change features admits alone and the Billing screen's free sentence reads, state
 * active with no subscriptions row and no trial end, and the staff threshold it shares with the
 * plan change close the file.
 */

/**
 * The catalogue the cases read: floor and staff under their test prices, or the plans given.
 *
 * @param  array<mixed>|null  $plans
 */
function tenantPlanCatalogue(?array $plans = null): PlanCatalogue
{
    return new PlanCatalogue($plans ?? [
        'floor' => ['name' => 'Solo', 'price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']],
        'staff' => ['name' => 'Team', 'price' => 'price_staff', 'features' => ['staff'], 'display' => ['amount' => 2495, 'currency' => 'EUR']],
    ], 7);
}

/**
 * The plan of a row of the shape given, every column a case leaves out as tenant:provision writes it.
 *
 * @param  array{status?: TenantStatus, state?: SubscriptionState, trialEnd?: bool, subscriptionRow?: bool, planRef?: string|null}  $row
 */
function tenantPlanOf(array $row, ?PlanCatalogue $catalogue = null): ?TenantPlan
{
    return TenantPlan::of(
        status: $row['status'] ?? TenantStatus::Active,
        state: $row['state'] ?? SubscriptionState::Active,
        trialEnd: $row['trialEnd'] ?? false,
        subscriptionRow: $row['subscriptionRow'] ?? false,
        planRef: $row['planRef'] ?? null,
        catalogue: $catalogue ?? tenantPlanCatalogue(),
    );
}

it('lists every plan by the five words the console filters on', function () {
    expect(array_map(fn (TenantPlan $plan): string => $plan->value, TenantPlan::cases()))->toBe(['trial', 'free', 'floor', 'staff', 'unlisted']);
});

it('derives {case}', function (TenantStatus $status, SubscriptionState $state, bool $trialEnd, bool $subscriptionRow, ?string $planRef, ?TenantPlan $plan) {
    expect(TenantPlan::of(status: $status, state: $state, trialEnd: $trialEnd, subscriptionRow: $subscriptionRow, planRef: $planRef, catalogue: tenantPlanCatalogue()))->toBe($plan);
})->with([
    // status, subscription state, trial end set, subscriptions row, price reference, plan
    'trial for a trial\'s build, before any checkout' => [TenantStatus::Active, SubscriptionState::Trial, true, false, null, TenantPlan::Trial],
    'trial for a trial with a checkout open beside it' => [TenantStatus::Active, SubscriptionState::Trial, true, true, null, TenantPlan::Trial],
    'free for the row tenant:provision writes, no subscriptions row and no trial end' => [TenantStatus::Active, SubscriptionState::Active, false, false, null, TenantPlan::Free],
    'free for a free row suspended' => [TenantStatus::Suspended, SubscriptionState::Active, false, false, null, TenantPlan::Free],
    'floor for a subscriptions row on the floor\'s price' => [TenantStatus::Active, SubscriptionState::Active, false, true, 'price_floor', TenantPlan::Floor],
    'staff for a subscriptions row on the staff price' => [TenantStatus::Active, SubscriptionState::Active, false, true, 'price_staff', TenantPlan::Staff],
    'staff for a row in grace on the staff price' => [TenantStatus::Active, SubscriptionState::Grace, false, true, 'price_staff', TenantPlan::Staff],
    'the first catalogue plan among several prices, as the Billing screen reads it' => [TenantStatus::Active, SubscriptionState::Active, false, true, 'price_staff,price_floor', TenantPlan::Floor],
    'unlisted for a subscriptions row with no price reference' => [TenantStatus::Active, SubscriptionState::Active, false, true, null, TenantPlan::Unlisted],
    'unlisted for a subscriptions row on a price no plan names' => [TenantStatus::Active, SubscriptionState::PastDue, false, true, 'price_retired', TenantPlan::Unlisted],
    'unlisted for a trial that ended unpaid with no checkout ever opened' => [TenantStatus::Active, SubscriptionState::Canceled, true, false, null, TenantPlan::Unlisted],
    'unlisted for a trial that ended unpaid beside an abandoned checkout' => [TenantStatus::Active, SubscriptionState::Canceled, true, true, null, TenantPlan::Unlisted],
    'unlisted for a frozen state with no subscriptions row and no trial end, which no writer leaves, never free' => [TenantStatus::Active, SubscriptionState::Canceled, false, false, null, TenantPlan::Unlisted],
    'no plan for a provisioning row, whatever else it holds' => [TenantStatus::Provisioning, SubscriptionState::Incomplete, false, false, null, null],
    'no plan for a provisioning row on the floor\'s price' => [TenantStatus::Provisioning, SubscriptionState::Active, false, true, 'price_floor', null],
]);

it('answers unlisted rather than throwing where the catalogue cannot be read, and trial and free without reading it', function () {
    $unreadable = new PlanCatalogue(null, 7);
    $blank = tenantPlanCatalogue(['floor' => ['name' => 'Solo', 'price' => '', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']]]);

    expect(tenantPlanOf(['subscriptionRow' => true, 'planRef' => 'price_floor'], $unreadable))->toBe(TenantPlan::Unlisted)
        ->and(tenantPlanOf(['subscriptionRow' => true, 'planRef' => 'price_floor'], $blank))->toBe(TenantPlan::Unlisted)
        ->and(tenantPlanOf(['state' => SubscriptionState::Trial, 'trialEnd' => true], $unreadable))->toBe(TenantPlan::Trial)
        ->and(tenantPlanOf([], $unreadable))->toBe(TenantPlan::Free)
        ->and(tenantPlanOf(['status' => TenantStatus::Provisioning], $unreadable))->toBeNull();
});

it('derives a page of rows over the catalogue\'s plans parsed once, asking for them on a paid row alone', function () {
    $asked = 0;
    $parsed = TenantPlan::plansOf(tenantPlanCatalogue());
    $plans = function () use (&$asked, $parsed): ?array {
        $asked++;

        return $parsed;
    };

    $derived = [
        TenantPlan::among(TenantStatus::Active, SubscriptionState::Trial, true, false, null, $plans),
        TenantPlan::among(TenantStatus::Active, SubscriptionState::Active, false, false, null, $plans),
        TenantPlan::among(TenantStatus::Provisioning, SubscriptionState::Incomplete, false, false, null, $plans),
    ];

    expect($derived)->toBe([TenantPlan::Trial, TenantPlan::Free, null])
        ->and($asked)->toBe(0)
        ->and(TenantPlan::among(TenantStatus::Active, SubscriptionState::Active, false, true, 'price_staff', $plans))->toBe(TenantPlan::Staff)
        ->and($asked)->toBe(1)
        ->and(TenantPlan::among(TenantStatus::Active, SubscriptionState::Active, false, true, 'price_floor', fn (): ?array => null))->toBe(TenantPlan::Unlisted)
        ->and(array_map(fn ($plan) => $plan->key, $parsed ?? []))->toBe(['floor', 'staff'])
        ->and(TenantPlan::plansOf(new PlanCatalogue(null, 7)))->toBeNull()
        ->and(TenantPlan::sql(null))->toBe(TenantPlan::sql([]))
        ->and(TenantPlan::sql($parsed)[1])->toBe(['price_floor', 'price_staff']);
});

it('answers unlisted for a catalogue plan the console has no word for', function () {
    $catalogue = tenantPlanCatalogue([
        'premium' => ['name' => 'Premium', 'price' => 'price_premium', 'features' => ['staff'], 'display' => ['amount' => 4995, 'currency' => 'EUR']],
        'floor' => ['name' => 'Solo', 'price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']],
    ]);

    expect(tenantPlanOf(['subscriptionRow' => true, 'planRef' => 'price_premium'], $catalogue))->toBe(TenantPlan::Unlisted)
        ->and(tenantPlanOf(['subscriptionRow' => true, 'planRef' => 'price_floor'], $catalogue))->toBe(TenantPlan::Floor);
});

it('names floor and staff as the catalogue names them, in its order, a price unset as in development leaving the names standing', function () {
    $unset = tenantPlanCatalogue([
        'floor' => ['name' => 'Solo', 'price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']],
        'staff' => ['name' => 'Team', 'price' => null, 'features' => ['staff'], 'display' => ['amount' => 2495, 'currency' => 'EUR']],
    ]);
    $renamed = tenantPlanCatalogue([
        'staff' => ['name' => 'Studio', 'price' => 'price_staff', 'features' => ['staff'], 'display' => ['amount' => 2495, 'currency' => 'EUR']],
        'floor' => ['name' => 'Starter', 'price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']],
    ]);

    expect(TenantPlan::names(tenantPlanCatalogue()))->toBe([['plan' => 'floor', 'name' => 'Solo'], ['plan' => 'staff', 'name' => 'Team']])
        ->and(TenantPlan::names($unset))->toBe([['plan' => 'floor', 'name' => 'Solo'], ['plan' => 'staff', 'name' => 'Team']])
        ->and(TenantPlan::names($renamed))->toBe([['plan' => 'staff', 'name' => 'Studio'], ['plan' => 'floor', 'name' => 'Starter']]);
});

it('names no plan the console has no word for, and none where the catalogue cannot name its plans', function () {
    $premium = tenantPlanCatalogue([
        'premium' => ['name' => 'Premium', 'price' => 'price_premium', 'features' => ['staff'], 'display' => ['amount' => 4995, 'currency' => 'EUR']],
        'floor' => ['name' => 'Solo', 'price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']],
    ]);
    $nameless = tenantPlanCatalogue(['floor' => ['price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']]]);

    expect(TenantPlan::names($premium))->toBe([['plan' => 'floor', 'name' => 'Solo']])
        ->and(TenantPlan::names(new PlanCatalogue(null, 7)))->toBe([])
        ->and(TenantPlan::names($nameless))->toBe([]);
});

it('reads the free shape as the plan does, the one shape the console\'s Change features admits: {case}', function (SubscriptionState $state, bool $trialEnd, bool $subscriptionRow, bool $free) {
    expect(TenantPlan::free($state, $trialEnd, $subscriptionRow))->toBe($free)
        ->and(tenantPlanOf(['state' => $state, 'trialEnd' => $trialEnd, 'subscriptionRow' => $subscriptionRow]) === TenantPlan::Free)->toBe($free);
})->with([
    'no subscriptions row and no trial end' => [SubscriptionState::Active, false, false, true],
    'a subscriptions row' => [SubscriptionState::Active, false, true, false],
    'a trial, which has no subscriptions row either' => [SubscriptionState::Trial, true, false, false],
    'a trial that ended unpaid' => [SubscriptionState::Canceled, true, false, false],
    'canceled with no subscriptions row and no trial end, a frozen state no writer leaves' => [SubscriptionState::Canceled, false, false, false],
    'a charge unfinished with no subscriptions row and no trial end' => [SubscriptionState::Incomplete, false, false, false],
    'past due with no subscriptions row and no trial end' => [SubscriptionState::PastDue, false, false, false],
    'active beside a trial end and no subscriptions row' => [SubscriptionState::Active, true, false, false],
]);

it('refuses a set without staff while more than one staff member is active, and nothing else, by the plan change\'s own threshold', function () {
    expect(PlanChange::featureSetRefusal(Entitlements::none(), 2)?->count)->toBe(PlanChangeRefused::downgrade(2)->count)
        ->and(PlanChange::featureSetRefusal(Entitlements::none(), 2)?->reason)->toBe(PlanChangeRefused::DOWNGRADE)
        ->and(PlanChange::featureSetRefusal(Entitlements::none(), 1))->toBeNull()
        ->and(PlanChange::featureSetRefusal(Entitlements::all(), 5))->toBeNull();
});
