<?php

use App\Billing\Narrowing;
use App\Billing\StoredEntitlementResolver;
use App\Tenancy\Tenant;

/*
 * The stored entitlement resolver over the tenant row alone: the stored slugs filtered to the
 * features this build knows while the cached state is active, grace or trial, and the floor
 * otherwise. The row is built in memory: the resolver reads the model it is handed and nothing
 * else, so no database and no application is needed to ask it.
 */

function rowOf(string $state, string $entitlements, string $narrowed = '{}'): Tenant
{
    return (new Tenant)->setRawAttributes([
        'slug' => 'luna',
        'subscription_state' => $state,
        'entitlements' => $entitlements,
        'narrowed' => $narrowed,
    ]);
}

it('answers the stored slugs known to this build while the state is active, grace or trial, and the floor in every other state', function (string $state, string $stored, array $slugs) {
    expect((new StoredEntitlementResolver)->resolve(rowOf($state, $stored))->toSlugs())->toBe($slugs);
})->with([
    'active with staff' => ['active', '{staff}', ['staff']],
    'grace keeps the whole set' => ['grace', '{staff}', ['staff']],
    'trial answers the stored set' => ['trial', '{staff}', ['staff']],
    'past_due freezes to the floor' => ['past_due', '{staff}', []],
    'canceled freezes to the floor' => ['canceled', '{staff}', []],
    'incomplete freezes to the floor' => ['incomplete', '{staff}', []],
    'an unknown slug beside a known one is dropped' => ['active', '{staff,nonsense}', ['staff']],
    'an unknown slug alone is the floor' => ['active', '{nonsense}', []],
    'the empty set is the floor' => ['active', '{}', []],
    'a repeated slug spells once' => ['grace', '{staff,staff}', ['staff']],
]);

it('falls closed to the floor on a state outside the six', function () {
    expect((new StoredEntitlementResolver)->resolve(rowOf('suspended', '{staff}'))->toSlugs())->toBe([]);
});

it('falls closed to the floor on a stored value that is not an array literal', function () {
    expect((new StoredEntitlementResolver)->resolve(rowOf('active', 'staff'))->toSlugs())->toBe([]);
});

it('reads the narrowing record filtered to the slugs this build knows, whatever the state', function (string $state, string $narrowed, array $slugs) {
    expect(Narrowing::of(rowOf($state, '{}', $narrowed)))->toBe($slugs);
})->with([
    'a known slug' => ['past_due', '{staff}', ['staff']],
    'an unknown slug beside it is dropped' => ['active', '{nonsense,staff}', ['staff']],
    'unknown slugs alone name nothing' => ['active', '{nonsense}', []],
    'the empty record' => ['canceled', '{}', []],
    'a repeated slug names it once' => ['active', '{staff,staff}', ['staff']],
    'a value that is not an array literal names nothing' => ['active', 'staff', []],
]);

it('decodes the narrowed column\'s literal as the database hands it back the same way, the one decoder the sync reads its locked row through', function (mixed $literal, array $slugs) {
    expect(Narrowing::ofLiteral($literal))->toBe($slugs);
})->with([
    'a known slug' => ['{staff}', ['staff']],
    'an unknown slug beside it is dropped' => ['{nonsense,staff}', ['staff']],
    'unknown slugs alone name nothing' => ['{nonsense}', []],
    'the empty record' => ['{}', []],
    'a repeated slug names it once' => ['{staff,staff}', ['staff']],
    'a value that is not an array literal names nothing' => ['staff', []],
    'a column the row does not carry names nothing' => [null, []],
]);
