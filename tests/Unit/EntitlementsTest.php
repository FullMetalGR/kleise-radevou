<?php

use App\Billing\Entitlements;
use App\Billing\Feature;

/*
 * Feature and Entitlements: one case, one general predicate, and the empty set a value like
 * any other rather than a failure.
 */

it('has exactly one feature, staff, and no case for the floor', function () {
    expect(Feature::cases())->toBe([Feature::Staff])
        ->and(Feature::Staff->value)->toBe('staff');
});

it('exposes allows, toSlugs, fromSlugs, all and none and no named predicate', function () {
    $public = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            (new ReflectionClass(Entitlements::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
        ),
    );
    sort($public);

    expect($public)->toBe(['all', 'allows', 'fromSlugs', 'none', 'toSlugs']);
});

it('allows every feature from all() and none from none()', function () {
    expect(Entitlements::all()->allows(Feature::Staff))->toBeTrue()
        ->and(Entitlements::none()->allows(Feature::Staff))->toBeFalse()
        ->and(Entitlements::all()->toSlugs())->toBe(['staff'])
        ->and(Entitlements::none()->toSlugs())->toBe([]);
});

it('reads slugs it knows and drops the ones it does not, since an unknown plan buys the floor', function () {
    expect(Entitlements::fromSlugs(['staff'])->allows(Feature::Staff))->toBeTrue()
        ->and(Entitlements::fromSlugs(['staff', 'staff'])->toSlugs())->toBe(['staff'])
        ->and(Entitlements::fromSlugs(['gold', 'Staff'])->toSlugs())->toBe([])
        ->and(Entitlements::fromSlugs([])->toSlugs())->toBe([]);
});
