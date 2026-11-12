<?php

use App\Billing\CatalogueUnreadable;
use App\Billing\MalformedSnapshot;
use App\Billing\PlanCatalogue;
use App\Billing\SnapshotItem;

/*
 * The plan catalogue: each plan names the provider price it is sold under, the feature slugs it
 * grants and what the Billing screen shows for it. A price this build does not know contributes
 * nothing, so an unknown plan is the floor and never the whole product. Built here from a plain
 * array, the shape config/billing.php holds.
 */

/**
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function catalogueConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'grace_days' => 7,
        'plans' => [
            'floor' => ['name' => 'Solo', 'price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']],
            'staff' => ['name' => 'Team', 'price' => 'price_staff', 'features' => ['staff'], 'display' => ['amount' => 2495, 'currency' => 'EUR']],
        ],
    ], $overrides);
}

/**
 * The catalogue as the container hands it over: the plans and the grace length, never the rest
 * of the billing configuration.
 *
 * @param  array<mixed>  $config
 */
function catalogueOf(array $config): PlanCatalogue
{
    return new PlanCatalogue($config['plans'] ?? null, $config['grace_days'] ?? null);
}

/**
 * @param  array<mixed>  $items  pairs of a price identifier and a quantity
 * @param  array<mixed>|null  $config
 * @return list<string>
 */
function slugsFor(array $items, ?array $config = null): array
{
    $lines = array_values(array_map(
        fn (mixed $item): SnapshotItem => is_array($item) && is_string($item[0] ?? null) && is_int($item[1] ?? null)
            ? new SnapshotItem($item[0], $item[1])
            : throw new LogicException('An item is a price identifier and a quantity.'),
        $items,
    ));

    return catalogueOf($config ?? catalogueConfig())->entitlementsFor($lines)->toSlugs();
}

it('resolves a snapshot\'s items to the features their plans grant', function (array $items, array $slugs) {
    expect(slugsFor($items))->toBe($slugs);
})->with([
    'the staff plan' => [[['price_staff', 1]], ['staff']],
    'the floor plan' => [[['price_floor', 1]], []],
    'an unknown identifier is the floor' => [[['price_nobody_deployed', 1]], []],
    'the staff plan beside an unknown one' => [[['price_nobody_deployed', 1], ['price_staff', 1]], ['staff']],
    'no items at all' => [[], []],
    'a quantity of nought buys nothing' => [[['price_staff', 0]], []],
]);

it('names every plan with its name, its price identifier, its features and its display price', function () {
    $plans = catalogueOf(catalogueConfig())->plans();

    expect(array_map(fn ($plan): array => [$plan->key, $plan->name, $plan->priceId, $plan->features->toSlugs(), $plan->price->minor, $plan->price->currency->code], $plans))
        ->toBe([
            ['floor', 'Solo', 'price_floor', [], 1495, 'EUR'],
            ['staff', 'Team', 'price_staff', ['staff'], 2495, 'EUR'],
        ]);
});

it('outlines every plan by its key, its name and its features, read without the price it is sold under or the amount it displays', function (array $config) {
    $outlines = catalogueOf($config)->outlines();

    expect(array_map(fn ($outline): array => [$outline->key, $outline->name, $outline->features->toSlugs()], $outlines))
        ->toBe([
            ['floor', 'Solo', []],
            ['staff', 'Team', ['staff']],
        ]);
})->with([
    'a readable catalogue' => [catalogueConfig()],
    'a price unset as in development' => [catalogueConfig(['plans' => ['staff' => ['price' => null]]])],
    'a price blank' => [catalogueConfig(['plans' => ['floor' => ['price' => '']]])],
    'a display amount nobody can read' => [catalogueConfig(['plans' => ['staff' => ['display' => ['amount' => '39.00']]]])],
    'two plans under one price' => [catalogueConfig(['plans' => ['floor' => ['price' => 'price_staff']]])],
]);

it('refuses an outline it cannot name as it refuses a plan', function (array $config, string $cause) {
    expect(fn () => catalogueOf($config)->outlines())->toThrow(CatalogueUnreadable::class, $cause)
        ->and(fn () => catalogueOf($config)->plans())->toThrow(CatalogueUnreadable::class, $cause);
})->with([
    'no plans' => [['plans' => 'none'], 'The billing catalogue holds no list of plans.'],
    'a plan that is not a map' => [catalogueConfig(['plans' => ['staff' => 'cheap']]), 'The billing plan "staff" is not a map.'],
    'a plan with no name' => [['plans' => ['floor' => ['price' => 'price_floor', 'features' => [], 'display' => ['amount' => 1495, 'currency' => 'EUR']]]], 'The billing plan "floor" carries no name to be shown by.'],
    'a blank name' => [catalogueConfig(['plans' => ['staff' => ['name' => '']]]), 'The billing plan "staff" carries no name to be shown by.'],
    'a name that is not text' => [catalogueConfig(['plans' => ['staff' => ['name' => 12]]]), 'The billing plan "staff" carries no name to be shown by.'],
    'a feature this build does not know' => [catalogueConfig(['plans' => ['staff' => ['features' => ['staff', 'gold']]]]), 'The billing plan "staff" grants "gold", which is not a feature this build knows.'],
]);

it('finds a plan by its key and refuses a key it does not hold', function () {
    $catalogue = catalogueOf(catalogueConfig());

    expect($catalogue->plan('staff')->priceId)->toBe('price_staff')
        ->and(fn () => $catalogue->plan('gold'))->toThrow(CatalogueUnreadable::class, 'The billing catalogue has no plan "gold".');
});

it('refuses a plan whose price identifier the environment leaves unset or blank, naming the plan and its key', function (?string $price) {
    $catalogue = catalogueOf(catalogueConfig(['plans' => ['staff' => ['price' => $price]]]));
    $cause = 'The billing plan "staff" is sold under no price identifier: billing.plans.staff.price is unset or blank in the environment.';

    expect(fn () => $catalogue->entitlementsFor([new SnapshotItem('price_floor', 1)]))->toThrow(CatalogueUnreadable::class, $cause)
        ->and(fn () => $catalogue->plan('floor'))->toThrow(CatalogueUnreadable::class, $cause);
})->with(['unset' => [null], 'blank' => ['']]);

it('reads the grace length in days', function () {
    expect(catalogueOf(catalogueConfig())->graceDays())->toBe(7);
});

it('refuses a catalogue it cannot read rather than guessing at it', function (array $config, string $cause) {
    expect(fn () => slugsFor([['price_staff', 1]], $config))->toThrow(CatalogueUnreadable::class, $cause);
})->with([
    'no plans' => [['plans' => 'none'], 'The billing catalogue holds no list of plans.'],
    'a plan that is not a map' => [catalogueConfig(['plans' => ['staff' => 'cheap']]), 'The billing plan "staff" is not a map.'],
    'a feature this build does not know' => [catalogueConfig(['plans' => ['staff' => ['features' => ['staff', 'gold']]]]), 'The billing plan "staff" grants "gold", which is not a feature this build knows.'],
    'a price that is not text' => [catalogueConfig(['plans' => ['staff' => ['price' => 12]]]), 'The billing plan "staff" names no price identifier as text.'],
    'a display amount that is not an integer' => [catalogueConfig(['plans' => ['staff' => ['display' => ['amount' => '39.00']]]]), 'The billing plan "staff" displays no whole amount in minor units with a currency.'],
    'a display currency that is not one' => [catalogueConfig(['plans' => ['staff' => ['display' => ['currency' => 'euro']]]]), 'The billing plan "staff" displays no whole amount in minor units with a currency.'],
    'two plans under one price' => [catalogueConfig(['plans' => ['floor' => ['price' => 'price_staff']]]), 'Two billing plans are sold under the price "price_staff".'],
]);

it('refuses a grace length that is not a whole number of days', function (mixed $days) {
    expect(fn () => catalogueOf(catalogueConfig(['grace_days' => $days]))->graceDays())
        ->toThrow(CatalogueUnreadable::class, 'The billing grace length is not a whole number of days above nought.');
})->with([0, -1, '7', null]);

it('refuses an item list it cannot read as a malformed snapshot', function (string $price, int $quantity) {
    expect(fn () => slugsFor([['price_staff', 1], [$price, $quantity]]))->toThrow(MalformedSnapshot::class);
})->with([
    'an empty price identifier' => ['', 1],
    'a quantity below nought' => ['price_staff', -1],
]);
