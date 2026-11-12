<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Billing\BillingWebhook;
use App\Billing\Checkout;
use App\Billing\CheckoutCompletion;
use App\Billing\EntitlementResolver;
use App\Billing\Entitlements;
use App\Billing\NoticeKind;
use App\Billing\NoticeUnhandled;
use App\Billing\SignupCompletion;
use App\Billing\SyncOutcome;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeBillingProvider;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The checkout completion: the provider's completed checkout lands the subscription the session
 * names on a built tenant's row, the way back's checkout, and builds nothing; the signup opens no
 * checkout, so a row never built is met with a line at warning, never an error, and left as it
 * was (P9F-50b).
 */

/**
 * luna built and in its trial, the provider's customer cus_{slug} and the way back's checkout
 * recorded on its subscriptions row, as the Billing screen's checkout leaves a trial tenant.
 */
function completionTrial(string $slug = 'luna'): Tenant
{
    $tenant = billingTenant($slug);
    billingSet(['subscription_state' => 'trial', 'entitlements' => '{staff}', 'trial_ends_at' => app(Clock::class)->now()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval('P7D'))->format('Y-m-d H:i:sP')], $slug);

    return $tenant;
}

/**
 * A row reserved and never built with a customer recorded beside it, which no road in the
 * product writes since the signup took no checkout: the arrangement for the guard alone.
 */
function completionReserved(): Tenant
{
    $tenant = app(TenantProvisioner::class)->reserve(signupFor('luna'));
    syncWith()->attachCustomer($tenant, openedCheckout('cus_luna'));

    return $tenant;
}

/**
 * @return TestResponse<Response>
 */
function completionDelivered(TestCase $test, string $eventId = 'evt_completed', NoticeKind $kind = NoticeKind::CheckoutCompleted): TestResponse
{
    return deliverBillingNotice($test, FakeBillingProvider::notice($eventId, $kind, 'cus_luna', 'sub_luna'));
}

/**
 * What provisioning builds, read back, or the status alone while no schema exists.
 *
 * @return array<string, mixed>
 */
function completionBuilt(string $slug = 'luna'): array
{
    $schema = 'tenant_'.$slug;
    $status = DB::table('tenants')->where('slug', $slug)->value('status');

    if (! DB::table('pg_namespace')->where('nspname', $schema)->exists()) {
        return ['status' => $status];
    }

    return [
        'status' => $status,
        'migrations' => DB::table($schema.'.migrations')->count(),
        'settings' => DB::table($schema.'.settings')->count(),
        'implicit' => DB::table($schema.'.staff')->where('is_implicit', true)->whereNull('user_id')->count(),
        'week' => DB::table($schema.'.shift_patterns')->count(),
    ];
}

it('is the checkout seam the container binds', function () {
    expect(get_class(app(CheckoutCompletion::class)))->toBe(SignupCompletion::class);
});

it('writes nothing on a row never built, asking the provider nothing, and leaves a warning line (P9F-50b)', function () {
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    completionReserved();
    $before = [billingColumns(), subscriptionColumns(), checkoutRecord()];

    expect(app(SignupCompletion::class)->completed('cus_luna', 'sub_luna', subscriptionRevision()))->toBeNull()
        ->and(completionBuilt())->toBe(['status' => 'provisioning'])
        ->and([billingColumns(), subscriptionColumns(), checkoutRecord()])->toBe($before)
        ->and(billingPortReads())->toBe([])
        ->and($log->records)->toBe([[
            'level' => 'warning',
            'message' => SignupCompletion::UNBUILT,
            'context' => ['tenant' => 'luna', 'customer' => 'cus_luna', 'subscription' => 'sub_luna'],
        ]]);
});

it('marks a completed checkout delivered through the webhook for a row never built, writing nothing, asking the provider nothing, with one warning line (P9F-50b)', function () {
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    completionReserved();
    $before = [billingColumns(), subscriptionColumns(), checkoutRecord()];

    completionDelivered($this)->assertOk();

    expect(completionBuilt())->toBe(['status' => 'provisioning'])
        ->and([billingColumns(), subscriptionColumns(), checkoutRecord()])->toBe($before)
        ->and(billingPortReads())->toBe([])
        ->and(billingWebhookEvent('evt_completed')['processed_at'])->not->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'warning',
            'message' => SignupCompletion::UNBUILT,
            'context' => ['tenant' => 'luna', 'customer' => 'cus_luna', 'subscription' => 'sub_luna'],
        ]]);
});

it('lands a trial tenant\'s first subscription on the built row, clearing its trial end and the checkout record and building nothing, and a repeat changes nothing', function () {
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    completionDelivered($this)->assertOk();

    expect(completionBuilt())->toBe(['status' => 'active'])
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'active', 'entitlements' => '{staff}'])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('trial_ends_at'))->toBeNull()
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_luna')
        ->and(billingPortReads())->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(checkoutRecord())->toBe(['checkout_session_id' => null, 'checkout_url' => null, 'checkout_opened_at' => null]);

    $before = [billingColumns(), subscriptionColumns(), completionBuilt(), subscriptionRevision(), billingPortReads()];

    completionDelivered($this, 'evt_again')->assertOk();

    expect([billingColumns(), subscriptionColumns(), completionBuilt(), subscriptionRevision(), billingPortReads()])->toBe($before)
        ->and(billingWebhookEvent('evt_again')['processed_at'])->not->toBeNull();
});

it('lands nothing more on a completion arriving after the subscription-created event landed the plan, asking the provider nothing more and clearing the checkout record', function () {
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    completionDelivered($this, 'evt_created', NoticeKind::SubscriptionChanged)->assertOk();
    expect(billingColumns()['subscription_state'])->toBe('active');
    $reads = billingPortReads();

    expect(checkoutRecord()['checkout_session_id'])->toBe('cs_cus_luna')
        ->and(app(SignupCompletion::class)->completed('cus_luna', 'sub_luna', subscriptionRevision()))->toBeNull()
        ->and(completionBuilt()['status'])->toBe('active')
        ->and(billingColumns()['entitlements'])->toBe('{staff}')
        ->and(billingPortReads())->toBe($reads)
        ->and(checkoutRecord())->toBe(['checkout_session_id' => null, 'checkout_url' => null, 'checkout_opened_at' => null]);
});

it('reads the subscription again when an earlier event left it incomplete, so the owner meets the plan the checkout paid for', function () {
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'incomplete', ['price_staff_test']));
    completionDelivered($this, 'evt_created', NoticeKind::SubscriptionChanged)->assertOk();
    expect(billingColumns()['subscription_state'])->toBe('incomplete');
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    completionDelivered($this)->assertOk();

    expect(billingColumns()['subscription_state'])->toBe('active')
        ->and(completionBuilt()['status'])->toBe('active')
        ->and(count(billingPortReads()))->toBe(2);
});

it('reads the row\'s own subscription again at incomplete and lands the provider\'s answer, an expired first charge as canceled, never naming it as over the current one', function () {
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'incomplete', ['price_staff_test']));
    completionDelivered($this, 'evt_created', NoticeKind::SubscriptionChanged)->assertOk();
    expect(billingColumns()['subscription_state'])->toBe('incomplete');
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'incomplete_expired', ['price_staff_test']));

    completionDelivered($this)->assertOk();

    expect(billingColumns()['subscription_state'])->toBe('canceled')
        ->and(subscriptionColumns())->toMatchArray(['provider_subscription_id' => 'sub_luna', 'status' => 'incomplete_expired'])
        ->and(checkoutRecord())->toBe(['checkout_session_id' => null, 'checkout_url' => null, 'checkout_opened_at' => null])
        ->and(billingWebhookEvent('evt_completed')['processed_at'])->not->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'info',
            'message' => SignupCompletion::BUILT,
            'context' => ['tenant' => 'luna', 'customer' => 'cus_luna', 'subscription' => 'sub_luna'],
        ]]);
});

it('lands a refresh that answers incomplete on the built row, building nothing, the stored entitlements kept as the sync wrote them and the resolver answering the floor, the checkout record cleared and the event marked', function () {
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'incomplete', ['price_staff_test']));

    completionDelivered($this)->assertOk();

    expect(completionBuilt())->toBe(['status' => 'active'])
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'incomplete', 'entitlements' => '{staff}', 'plan_ref' => 'price_staff_test'])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('trial_ends_at'))->toBeNull()
        ->and(app(EntitlementResolver::class)->resolve(Tenant::query()->where('slug', 'luna')->sole())->toSlugs())->toBe(Entitlements::none()->toSlugs())
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_luna')
        ->and(billingPortReads())->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(checkoutRecord())->toBe(['checkout_session_id' => null, 'checkout_url' => null, 'checkout_opened_at' => null])
        ->and(billingWebhookEvent('evt_completed')['processed_at'])->not->toBeNull();
});

it('lands nothing when the sync did not land the pair', function (int $revisionOffset, string $customer, SyncOutcome $answer) {
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', $customer, 'active', ['price_staff_test']));

    expect(app(SignupCompletion::class)->completed('cus_luna', 'sub_luna', subscriptionRevision() + $revisionOffset))->toBe($answer)
        ->and(completionBuilt())->toBe(['status' => 'active'])
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'trial', 'entitlements' => '{staff}'])
        ->and(subscriptionColumns()['provider_subscription_id'])->toBeNull()
        ->and(checkoutRecord()['checkout_session_id'])->toBe('cs_cus_luna');
})->with([
    'a revision that moved' => [1, 'cus_luna', SyncOutcome::Stale],
    'a subscription the provider says is another customer\'s' => [0, 'cus_other', SyncOutcome::UnknownTenant],
]);

/**
 * luna built and subscribed once, sub_old ended at the provider and the row canceled on it, the
 * webhook having taken staff away on the way so the record names it, then the owner's way back
 * opening a checkout for the same customer from the Billing screen.
 */
function completionCanceled(): void
{
    $tenant = billingTenant('luna');
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'active', ['price_staff_test']));
    syncWith()->refresh('sub_old');
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'canceled', ['price_floor_test']));
    syncWith()->refresh('sub_old');
    syncWith()->forgetCheckout($tenant->id);
    syncWith()->attachCustomer($tenant, new Checkout('cus_luna', 'cs_back', 'https://checkout.test/back'));
}

it('lands the resubscriber\'s new subscription on the built row, provisions nothing and says so, clears the checkout record, and a repeat is the no-op', function () {
    completionCanceled();
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    expect(billingColumns())->toMatchArray(['subscription_state' => 'canceled', 'entitlements' => '{}', 'narrowed' => '{staff}']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    completionDelivered($this)->assertOk();

    expect(subscriptionColumns()['provider_subscription_id'])->toBe('sub_luna')
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'active', 'entitlements' => '{staff}', 'narrowed' => '{}', 'plan_ref' => 'price_staff_test'])
        ->and(completionBuilt())->toBe(['status' => 'active'])
        ->and(checkoutRecord())->toBe(['checkout_session_id' => null, 'checkout_url' => null, 'checkout_opened_at' => null])
        ->and(billingWebhookEvent('evt_completed')['processed_at'])->not->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'info',
            'message' => SignupCompletion::BUILT,
            'context' => ['tenant' => 'luna', 'customer' => 'cus_luna', 'subscription' => 'sub_luna'],
        ]]);

    $before = [billingColumns(), subscriptionColumns(), completionBuilt(), subscriptionRevision(), billingPortReads()];
    completionDelivered($this, 'evt_again')->assertOk();

    expect([billingColumns(), subscriptionColumns(), completionBuilt(), subscriptionRevision(), billingPortReads()])->toBe($before)
        ->and(billingWebhookEvent('evt_again')['processed_at'])->not->toBeNull()
        ->and($log->records)->toHaveCount(1);
});

it('leaves the way back\'s open checkout alone when a completion for the ended pair arrives again, asking the provider nothing', function () {
    completionCanceled();
    $before = [billingColumns(), subscriptionColumns(), subscriptionRevision(), checkoutRecord()];
    $reads = count(billingPortReads());

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_old', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_old'))->assertOk();

    expect([billingColumns(), subscriptionColumns(), subscriptionRevision(), checkoutRecord()])->toBe($before)
        ->and(checkoutRecord()['checkout_session_id'])->toBe('cs_back')
        ->and(billingPortReads())->toHaveCount($reads);
});

it('forgets the way back\'s checkout when its completion names a subscription the provider already holds ended, landing nothing over the row, with a line', function () {
    completionCanceled();
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'incomplete_expired', ['price_staff_test']));
    $before = [billingColumns(), subscriptionColumns(), subscriptionRevision()];

    completionDelivered($this)->assertOk();

    expect([billingColumns(), subscriptionColumns(), subscriptionRevision()])->toBe($before)
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_old')
        ->and(checkoutRecord())->toBe(['checkout_session_id' => null, 'checkout_url' => null, 'checkout_opened_at' => null])
        ->and(billingWebhookEvent('evt_completed')['processed_at'])->not->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'warning',
            'message' => SignupCompletion::ENDED,
            'context' => ['tenant' => 'luna', 'subscription' => 'sub_luna', 'current' => 'sub_old'],
        ]]);
});

it('ignores a late completion for the ended pair once the new subscription has landed, reading it ended and writing nothing over it, with a line', function () {
    completionCanceled();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    completionDelivered($this)->assertOk();
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'canceled', ['price_floor_test']));
    $before = [billingColumns(), subscriptionColumns(), subscriptionRevision(), checkoutRecord()];
    $reads = count(billingPortReads()) + 1;

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_old', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_old'))->assertOk();

    expect([billingColumns(), subscriptionColumns(), subscriptionRevision(), checkoutRecord()])->toBe($before)
        ->and(billingPortReads())->toHaveCount($reads)
        ->and(billingWebhookEvent('evt_old')['processed_at'])->not->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'warning',
            'message' => SignupCompletion::ENDED,
            'context' => ['tenant' => 'luna', 'subscription' => 'sub_old', 'current' => 'sub_luna'],
        ]]);
});

it('leaves a completed checkout unmarked with one handling line, writing no billing column, when the sync cannot land the pair', function (Closure $arrange, string $unhandled, int $reads) {
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    completionTrial();
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    $before = billingColumns();
    $arrange();

    try {
        completionDelivered($this)->assertStatus(500);
    } finally {
        Checkpoint::observe(null);
    }

    $lines = array_values(array_filter($log->records, fn (array $record): bool => $record['message'] === BillingWebhook::UNHANDLED));

    expect(completionBuilt())->toBe(['status' => 'active'])
        ->and(billingColumns())->toBe($before)
        ->and(subscriptionColumns()['provider_subscription_id'])->toBeNull()
        ->and(billingWebhookEvent('evt_completed')['processed_at'])->toBeNull()
        ->and(billingPortReads())->toHaveCount($reads)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context']['message'] ?? null)->toBe($unhandled);
})->with([
    'cannot resolve the plan' => [fn () => config(['billing.plans.staff.price' => '']), NoticeUnhandled::unresolved()->getMessage(), 1],
    'finds the row moved on every attempt' => [fn () => Checkpoint::observe(function (string $name): void {
        if ($name === FakeBillingProvider::ANSWERED) {
            DB::update('update subscriptions set revision = revision + 1');
        }
    }), NoticeUnhandled::contended()->getMessage(), BillingWebhook::ATTEMPTS],
]);

it('logs a completed checkout that finds no row and answers nothing', function () {
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);

    expect(app(SignupCompletion::class)->completed('cus_gone', 'sub_gone', 0))->toBeNull()
        ->and($log->records)->toBe([[
            'level' => 'error',
            'message' => SignupCompletion::NO_ROW,
            'context' => ['customer' => 'cus_gone', 'subscription' => 'sub_gone'],
        ]]);
});

it('leaves the row as it was when the refresh landed on another, a provider naming another known customer, with a line', function () {
    $log = new SeamLog;
    app()->instance(LoggerInterface::class, $log);
    $luna = completionTrial('luna');
    completionTrial('mira');
    billingFake()->script(billingSnapshot('sub_luna', 'cus_mira', 'active', ['price_staff_test']));
    $revision = DB::table('subscriptions')->where('tenant_id', $luna->id)->value('revision');
    assert(is_int($revision));

    expect(app(SignupCompletion::class)->completed('cus_luna', 'sub_luna', $revision))->toBe(SyncOutcome::Applied)
        ->and(billingColumns('luna'))->toMatchArray(['subscription_state' => 'trial'])
        ->and(subscriptionColumns()['provider_subscription_id'] ?? null)->toBeNull()
        ->and(array_values(array_filter($log->records, fn (array $record): bool => $record['message'] === SignupCompletion::NOT_LANDED)))->toBe([[
            'level' => 'error',
            'message' => SignupCompletion::NOT_LANDED,
            'context' => ['tenant' => $luna->id, 'customer' => 'cus_luna', 'subscription' => 'sub_luna'],
        ]]);
});
