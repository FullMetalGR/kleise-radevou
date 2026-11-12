<?php

use App\Application\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;

pest()->use(DatabaseTruncation::class);

/*
 * The subscription sync writes the tenant row and the subscriptions row in one transaction.
 * Proven outside a wrapping transaction, truncating instead: inside one, the failed statement
 * would abort the test's own transaction and the proof would fail for that reason whether the
 * sync opened a transaction or not.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00'));
    billingTenant('luna');
    billingTenant('pier');
});

// Truncation runs before a test and not after it, so the rows go here, before a file wrapped in
// transactions meets them.
afterEach(function () {
    DB::table('subscriptions')->delete();
    DB::table('tenants')->whereIn('slug', ['luna', 'pier'])->delete();
});

it('writes in one transaction: a snapshot whose write fails midway leaves every column as it was', function () {
    syncWith()->apply(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    billingSet(['narrowed' => '{staff}']);
    $tenantBefore = billingColumns('pier');
    $subscriptionBefore = subscriptionColumns('pier');

    // pier's snapshot names luna's subscription: the tenant row is written first, and the
    // subscriptions row after it breaks the unique identifier.
    expect(fn () => syncWith()->apply(billingSnapshot('sub_luna', 'cus_pier', 'active', ['price_staff_test'])))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(billingColumns('pier'))->toBe($tenantBefore)
        ->and(subscriptionColumns('pier'))->toBe($subscriptionBefore)
        ->and(billingColumns())->toMatchArray(['entitlements' => '{staff}', 'narrowed' => '{staff}']);
});
