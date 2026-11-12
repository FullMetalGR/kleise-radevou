<?php

use App\Billing\BillingProvider;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FakeBillingProvider;

/*
 * Every process a gate starts meets the fake billing provider: the test environment names it
 * in configuration, as it names the array mail transport, so a worker booting the application
 * from scratch binds it without any test base class having run there. A worker asked for the
 * portal answers the fake's address and opens no connection to anybody.
 */

it('binds the fake in a worker process booted from nothing but the test environment', function () {
    $ask = fn (): array => [
        'port' => app(BillingProvider::class)::class,
        'portal' => app(BillingProvider::class)->openPortal('cus_luna', 'https://luna.test/admin/billing'),
    ];

    $outcomes = ConcurrentRun::of(['worker' => $ask])->run();

    expect(returnedArray($outcomes['worker']))->toBe([
        'port' => FakeBillingProvider::class,
        'portal' => 'https://billing.test/portal/cus_luna',
    ]);
});
