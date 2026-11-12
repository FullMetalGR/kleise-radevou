<?php

use App\Application\Checkpoint;
use App\Billing\Resubscribe;
use App\Http\Controllers\Admin\BillingController;
use App\Models\User;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FakeBillingProvider;
use Tests\Support\LunaFixture;
use Tests\Support\SeamLog;

/*
 * Two presses of the way back at once: the Billing screen's checkout for a canceled tenant reads
 * the session the row records, asks the provider for a new one and records it, and two tabs
 * pressing within the provider's answer would each open a live subscription over one row. The
 * start takes the subscriptions row's lock before it reads the record, so the second press waits
 * for the first and then answers the session the first recorded, asking the provider nothing.
 *
 * The first press is stopped inside the provider's checkout, its lock held; the second is
 * released and observed blocked on that lock; the first then finishes. Each press is the use
 * case the checkout route calls, in a worker of its own with the fake the test environment
 * binds there, its own return URL in the session's URL so the two answers say whose session
 * each is. The workers stand on the system clock and no date is spelled here: the record's 24
 * hours run from the instant the first press opened it.
 *
 * A press that waits past the lock's timeout is answered as a checkout that could not be opened,
 * a retry, with one line naming the tenant; the holder there is a connection of the test's own,
 * which waits on nothing, so no worker is needed.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example']);
    DB::table('subscriptions')->insert([
        'tenant_id' => Tenant::query()->where('slug', 'luna')->sole()->id,
        'provider_customer_id' => 'cus_luna',
        'provider_subscription_id' => 'sub_old',
        'status' => 'canceled',
        'items' => json_encode([['price' => 'price_staff_test', 'quantity' => 1]], JSON_THROW_ON_ERROR),
    ]);
    billingSet(['subscription_state' => 'canceled', 'entitlements' => '{}', 'plan_ref' => 'price_staff_test']);
});

afterEach(function () {
    DB::purge('way_back_probe');
    DB::statement('drop schema if exists tenant_luna cascade');
});

/**
 * One press of Subscribe again on the staff plan, returning to the URL given, answering where it
 * sends the owner and how many checkouts it asked the provider for.
 */
function wayBackPress(string $returnUrl): Closure
{
    return function () use ($returnUrl): array {
        Checkpoint::reach('go');
        $fake = FakeBillingProvider::bound();
        $url = app(Resubscribe::class)->start(Tenant::query()->where('slug', 'luna')->sole(), 'staff', 'owner@luna.example', $returnUrl);

        return ['url' => $url, 'checkouts' => count(array_filter($fake->asked, fn (array $question): bool => $question[0] === 'startCheckout'))];
    };
}

it('opens one checkout for two presses of the way back at once, the second waiting on the row\'s lock and answering the session the first recorded, asking the provider nothing', function () {
    $run = ConcurrentRun::of(['first' => wayBackPress('https://luna.test/first'), 'second' => wayBackPress('https://luna.test/second')])
        ->holding(FakeBillingProvider::CHECKOUT, 'go')
        ->start();
    $run->release();
    $run->releaseCheckpoint('go', 'first');
    $run->awaitCheckpoint('first', FakeBillingProvider::CHECKOUT);
    $run->releaseCheckpoint(FakeBillingProvider::CHECKOUT, 'second');
    $run->releaseCheckpoint('go', 'second');
    $waiting = lockWaiter('select tenant_id from subscriptions');
    $run->releaseCheckpoint(FakeBillingProvider::CHECKOUT, 'first');
    $outcomes = $run->wait();
    $first = FakeBillingProvider::checkoutUrl('cus_luna', 'staff', 'https://luna.test/first');

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['first']))->toBe(['url' => $first, 'checkouts' => 1])
        ->and(returnedArray($outcomes['second']))->toBe(['url' => $first, 'checkouts' => 0])
        ->and(checkoutRecord())->toMatchArray(['checkout_session_id' => 'cs_fake_1', 'checkout_url' => $first])
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_old');
});

it('words a press that waits past the lock timeout as a checkout that could not be opened, a retry, logging the contention and asking the provider nothing', function () {
    $probe = LunaFixture::probe('way_back_probe');
    $probe->beginTransaction();
    $probe->select('select tenant_id from subscriptions where tenant_id = ? for update', [Tenant::query()->where('slug', 'luna')->sole()->id]);
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    $before = checkoutRecord();

    try {
        $press = $this->actingAs(User::query()->where('email', 'owner@luna.example')->sole())
            ->from(tenantUrl('luna', '/admin/billing'))
            ->post(tenantUrl('luna', '/admin/billing/checkout'), ['plan' => 'staff']);
    } finally {
        $probe->rollBack();
    }

    $press->assertRedirect(tenantUrl('luna', '/admin/billing'))
        ->assertSessionHas('flash', ['kind' => 'error', 'message' => adminLine(BillingController::CHECKOUT_FAILED)]);

    expect(billingFake()->asked)->toBe([])
        ->and(checkoutRecord())->toBe($before)
        ->and($log->records)->toBe([['level' => 'warning', 'message' => BillingController::CHECKOUT_BUSY_LOGGED, 'context' => ['tenant' => 'luna']]]);
});
