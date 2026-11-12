<?php

use App\Application\Clock;
use App\Billing\BillingProvider;
use App\Billing\BillingWebhook;
use App\Billing\CheckoutCompletion;
use App\Billing\NoticeKind;
use App\Billing\NoticeUnhandled;
use App\Billing\SyncOutcome;
use App\Http\ErrorEnvelope;
use App\Http\Middleware\RequiresBillingSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Tests\Support\BillingPortDecorator;
use Tests\Support\FakeBillingProvider;
use Tests\Support\FixedClock;
use Tests\Support\RecordingCompletion;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The billing webhook: the one door the billing provider knocks on. A notification is
 * verified by the port before anything is read or stored, recorded by its event id before
 * it is handled, and marked processed once it has been; a repeat of a marked event is a
 * no-op. Subscription events and a failed charge are applied by re-reading the subscription
 * through the port, never from the event.
 *
 * The rows are central rows alone, as the sync's own tests have them: no tenant schema is
 * provisioned. The notifications are the fake's own, signed with the secret it knows.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00'));
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);

    billingTenant('luna');
    billingTenant('pier');
});

it('answers a verified notification on the central host and records it by its event id, marked processed', function () {
    $payload = FakeBillingProvider::notice('evt_1', NoticeKind::Other, 'cus_luna', null, 'customer.updated');

    deliverBillingNotice($this, $payload)->assertOk();

    $stored = billingWebhookEvent('evt_1')['payload'] ?? null;

    expect(billingWebhookEvent('evt_1'))->toMatchArray(['provider_event_id' => 'evt_1', 'type' => 'customer.updated'])
        ->and(json_decode(is_string($stored) ? $stored : '', true))->toEqual(json_decode($payload, true))
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull();
});

it('does nothing more with an event of a kind that does not matter than record it and mark it', function () {
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::Other, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingPortReads())->toBe([])
        ->and(subscriptionColumns()['provider_subscription_id'])->toBeNull()
        ->and($this->log->records)->toBe([]);
});

it('has no route for the webhook on a tenant host', function () {
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::Other), url: tenantUrl('luna', '/webhooks/billing'))->assertNotFound();

    expect(DB::table('webhook_events')->count())->toBe(0);
});

it('runs the signature check and nothing else on the route: no tenant resolution, no session, no cross-site guard, no subscription gate', function () {
    // The router learns the middleware names and groups from the kernel at the first request,
    // so one is made before the route's middleware is resolved to the classes that would run.
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::Other))->assertOk();
    $route = Route::getRoutes()->getByName('webhooks.billing');
    assert($route !== null);

    expect($route->gatherMiddleware())->toBe(['web', RequiresBillingSignature::NAME])
        ->and($route->methods())->toBe(['POST'])
        ->and($route->uri())->toBe('webhooks/billing')
        ->and($route->getDomain())->toBe(parse_url(centralUrl(), PHP_URL_HOST))
        ->and(app('router')->gatherRouteMiddleware($route))->toBe([RequiresBillingSignature::class]);
});

it('sets no cookie on the provider, which has no session to keep', function () {
    $response = deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::Other));

    expect($response->headers->getCookies())->toBe([]);
});

it('refuses a notification its signature does not vouch for with the envelope, one log line, and nothing stored', function (Closure $signed) {
    $payload = FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna');

    $response = $signed($this, $payload);
    assert($response instanceof TestResponse);

    $response->assertStatus(400)->assertExactJson([
        'code' => 'invalid_signature',
        'message' => 'invalid_signature',
        'data' => ['status' => 400, 'detail' => RequiresBillingSignature::REFUSED_DETAIL],
    ]);
    expect(DB::table('webhook_events')->count())->toBe(0)
        ->and(billingPortReads())->toBe([])
        ->and($this->log->records)->toHaveCount(1)
        ->and($this->log->records[0]['level'])->toBe('warning')
        ->and($this->log->records[0]['message'])->toBe(RequiresBillingSignature::REFUSED_LOG);
})->with([
    'a signature under another secret' => [fn (TestCase $test, string $payload) => deliverBillingNotice($test, $payload, hash_hmac('sha256', $payload, 'not-the-secret'))],
    'no signature at all' => [fn (TestCase $test, string $payload) => deliverBillingNotice($test, $payload, '')],
    'a body changed after it was signed' => [fn (TestCase $test, string $payload) => deliverBillingNotice($test, str_replace('cus_luna', 'cus_pier', $payload), FakeBillingProvider::sign($payload))],
]);

it('applies a changed subscription by re-reading it through the port', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingPortReads())->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(billingColumns()['entitlements'])->toBe('{staff}')
        ->and(subscriptionColumns()['provider_subscription_id'])->toBe('sub_luna')
        ->and(subscriptionColumns()['status'])->toBe('active')
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull();
});

it('does nothing on a second delivery of an event already processed: no question to the port, no column moved', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    $payload = FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna');
    deliverBillingNotice($this, $payload)->assertOk();
    $before = [billingColumns(), subscriptionColumns(), billingWebhookEvent('evt_1'), billingPortReads()];

    // Were the event handled again, this is what the re-read would now land.
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'canceled', []));
    deliverBillingNotice($this, $payload)->assertOk();

    expect([billingColumns(), subscriptionColumns(), billingWebhookEvent('evt_1'), billingPortReads()])->toBe($before)
        ->and(DB::table('webhook_events')->count())->toBe(1)
        ->and($this->log->records)->toBe([]);
});

it('handles again an event recorded but never marked, as an attempt that threw midway leaves it, and marks it', function () {
    $payload = FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna');

    // Nothing is scripted, so the re-read throws after the event is recorded.
    deliverBillingNotice($this, $payload)->assertStatus(500);
    expect(billingWebhookEvent('evt_1')['processed_at'])->toBeNull()
        ->and(billingColumns()['entitlements'])->toBe('{}');

    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));
    deliverBillingNotice($this, $payload)->assertOk();

    expect(billingColumns()['entitlements'])->toBe('{staff}')
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull()
        ->and(DB::table('webhook_events')->count())->toBe(1);
});

it('leaves an event unmarked, for the provider to deliver again, when the sync cannot resolve what it re-read', function () {
    config(['billing.plans.staff.price' => '']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna'))->assertStatus(500);

    expect(billingWebhookEvent('evt_1')['processed_at'])->toBeNull()
        ->and(subscriptionColumns()['provider_subscription_id'])->toBeNull();
});

it('opens grace on a failed charge once the re-read shows the subscription past due', function () {
    billingSet(['subscription_state' => 'active']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'past_due', ['price_floor_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::ChargeFailed, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingPortReads())->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(billingColumns()['subscription_state'])->toBe('grace')
        ->and(billingColumns()['grace_ends_at'])->toBe('2026-10-08 09:00')
        ->and(billingColumns('pier')['subscription_state'])->toBe('active');
});

it('opens no grace on a failed charge whose subscription the re-read shows paid, as a success delivered before its failure leaves it', function () {
    billingSet(['subscription_state' => 'active']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::ChargeFailed, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingColumns()['subscription_state'])->toBe('active')
        ->and(billingColumns()['grace_ends_at'])->toBeNull()
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull()
        ->and($this->log->records)->toBe([[
            'level' => 'info',
            'message' => BillingWebhook::NOT_PAST_DUE,
            'context' => ['event' => 'evt_1', 'subscription' => 'sub_luna', 'status' => 'active'],
        ]]);
});

it('closes grace on a succeeded charge', function () {
    billingSet(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-05 09:00:00+00']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::ChargeSucceeded, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingColumns()['subscription_state'])->toBe('active')
        ->and(billingColumns()['grace_ends_at'])->toBeNull();
});

it('lands a deleted subscription as canceled through the re-read', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'canceled', ['price_floor_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionDeleted, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingPortReads())->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(billingColumns()['subscription_state'])->toBe('canceled')
        ->and(subscriptionColumns()['status'])->toBe('canceled');
});

it('logs and ignores an event naming a customer and subscription no row knows, touching no tenant', function (NoticeKind $kind) {
    $before = [billingColumns(), subscriptionColumns(), billingColumns('pier'), subscriptionColumns('pier')];

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', $kind, 'cus_nobody', 'sub_nobody'))->assertOk();

    expect([billingColumns(), subscriptionColumns(), billingColumns('pier'), subscriptionColumns('pier')])->toBe($before)
        ->and(billingPortReads())->toBe([])
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull()
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => BillingWebhook::UNKNOWN,
            'context' => ['event' => 'evt_1', 'customer' => 'cus_nobody', 'subscription' => 'sub_nobody'],
        ]]);
})->with([NoticeKind::CheckoutCompleted, NoticeKind::SubscriptionChanged, NoticeKind::SubscriptionDeleted, NoticeKind::ChargeFailed, NoticeKind::ChargeSucceeded]);

it('finds the tenant by the subscription when the event names no customer', function () {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_luna');
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, null, 'sub_luna'))->assertOk();

    expect(billingColumns()['entitlements'])->toBe('{staff}');
});

it('logs and ignores an event naming a subscription the row has moved on from, and never lets it overwrite the row', function (NoticeKind $kind) {
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_luna');
    billingSet(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-05 09:00:00+00']);
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'canceled', ['price_floor_test']));
    billingFake()->asked = [];
    $before = [billingColumns(), subscriptionColumns()];

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', $kind, 'cus_luna', 'sub_old'))->assertOk();

    expect([billingColumns(), subscriptionColumns()])->toBe($before)
        ->and(billingPortReads())->toBe([])
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull()
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => BillingWebhook::NOT_CURRENT,
            'context' => ['event' => 'evt_1', 'tenant' => 'luna', 'subscription' => 'sub_old', 'current' => 'sub_luna'],
        ]]);
})->with([NoticeKind::SubscriptionChanged, NoticeKind::SubscriptionDeleted, NoticeKind::ChargeFailed, NoticeKind::ChargeSucceeded]);

it('logs and ignores an event naming no subscription where one is needed to act on it', function (NoticeKind $kind) {
    billingSet(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-05 09:00:00+00']);
    $before = [billingColumns(), subscriptionColumns()];

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', $kind, 'cus_luna'))->assertOk();

    expect([billingColumns(), subscriptionColumns()])->toBe($before)
        ->and(billingPortReads())->toBe([])
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => BillingWebhook::NO_SUBSCRIPTION,
            'context' => ['event' => 'evt_1', 'type' => 'fake.'.$kind->value],
        ]]);
})->with([NoticeKind::CheckoutCompleted, NoticeKind::SubscriptionChanged, NoticeKind::SubscriptionDeleted, NoticeKind::ChargeFailed, NoticeKind::ChargeSucceeded]);

it('hands a completed checkout to the signup seam with the tenant\'s customer and the session\'s subscription', function () {
    $seam = new RecordingCompletion;
    app()->instance(CheckoutCompletion::class, $seam);

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_luna'))->assertOk();

    expect($seam->completed)->toBe([['cus_luna', 'sub_luna']])
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull();
});

it('hands a completed checkout on even when the row names another subscription, since checkout completion is what names a new one', function () {
    $seam = new RecordingCompletion;
    app()->instance(CheckoutCompletion::class, $seam);
    billingFake()->script(billingSnapshot('sub_old', 'cus_luna', 'canceled', ['price_floor_test']));
    syncWith()->refresh('sub_old');

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_new'))->assertOk();

    expect($seam->completed)->toBe([['cus_luna', 'sub_new']]);
});

it('closes no grace on a succeeded charge whose subscription the re-read shows past due, as a late success for an earlier invoice leaves it', function () {
    billingSet(['subscription_state' => 'grace', 'grace_ends_at' => '2026-10-05 09:00:00+00']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'past_due', ['price_floor_test']));
    $before = billingColumns();

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::ChargeSucceeded, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingColumns())->toBe($before)
        ->and(billingPortReads())->toBe([['subscription', ['subscription' => 'sub_luna']]])
        ->and(billingWebhookEvent('evt_1')['processed_at'])->not->toBeNull()
        ->and($this->log->records)->toBe([[
            'level' => 'info',
            'message' => BillingWebhook::NOT_ACTIVE,
            'context' => ['event' => 'evt_1', 'subscription' => 'sub_luna', 'status' => 'past_due'],
        ]]);
});

it('revives no lapsed tenant on a late succeeded charge while the re-read shows nothing paid', function () {
    billingSet(['subscription_state' => 'past_due']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'past_due', ['price_floor_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::ChargeSucceeded, 'cus_luna', 'sub_luna'))->assertOk();

    expect(billingColumns()['subscription_state'])->toBe('past_due');
});

it('answers a handling failure with the envelope, one log line of ours naming the event, and the framework\'s report of it keeping the trace, whatever the debug setting', function () {
    config(['app.debug' => true]);

    // Nothing is scripted, so the re-read throws after the event is recorded.
    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna'))
        ->assertStatus(500)
        ->assertExactJson(['code' => 'server_error', 'message' => 'server_error', 'data' => ['status' => 500, 'detail' => apiSentence(ErrorEnvelope::SERVER_ERROR_DETAIL)]]);

    $reported = $this->log->records[1]['context']['exception'] ?? null;
    $cause = $reported instanceof Throwable ? $reported->getPrevious() : null;

    expect(billingWebhookEvent('evt_1')['processed_at'])->toBeNull()
        ->and($this->log->records)->toHaveCount(2)
        ->and($this->log->records[0])->toBe([
            'level' => 'error',
            'message' => BillingWebhook::UNHANDLED,
            'context' => ['event' => 'evt_1', 'exception' => LogicException::class, 'message' => 'No snapshot is scripted for sub_luna.'],
        ])
        ->and($this->log->records[1]['level'])->toBe('error')
        ->and($this->log->records[1]['message'])->toBe(NoticeUnhandled::after(new LogicException)->getMessage())
        ->and($reported)->toBeInstanceOf(NoticeUnhandled::class)
        ->and($cause)->toBeInstanceOf(LogicException::class)
        ->and($cause?->getMessage())->toBe('No snapshot is scripted for sub_luna.')
        ->and($cause?->getFile())->toBe((new ReflectionClass(FakeBillingProvider::class))->getFileName())
        ->and($cause?->getLine())->toBeGreaterThan(0)
        ->and($cause?->getTrace())->not->toBeEmpty();
});

it('logs an event the sync cannot resolve once as a handling failure, beside the sync\'s own line naming the cause, and the framework reports it', function () {
    config(['billing.plans.staff.price' => '']);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_staff_test']));

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna'))
        ->assertStatus(500)
        ->assertJsonPath('code', 'server_error');

    expect(array_column($this->log->records, 'message'))->toBe(['The subscription sync left a tenant as it was', BillingWebhook::UNHANDLED, NoticeUnhandled::unresolved()->getMessage()])
        ->and($this->log->records[1]['context'])->toBe(['event' => 'evt_1', 'exception' => NoticeUnhandled::class, 'message' => NoticeUnhandled::unresolved()->getMessage()])
        ->and($this->log->records[2]['context']['exception'] ?? null)->toBeInstanceOf(NoticeUnhandled::class);
});

it('answers the envelope when no billing provider is configured to verify with', function () {
    config(['billing.provider.class' => null]);
    app()->forgetInstance(BillingProvider::class);

    $this->call('POST', centralUrl('/webhooks/billing'), [], [], [], ['CONTENT_TYPE' => 'application/json'], FakeBillingProvider::notice('evt_1', NoticeKind::Other))
        ->assertStatus(500)
        ->assertExactJson(['code' => 'server_error', 'message' => 'server_error', 'data' => ['status' => 500, 'detail' => apiSentence(ErrorEnvelope::SERVER_ERROR_DETAIL)]]);
});

it('answers a 404 and a 405 under the webhook path with the envelope, never a framework page, whatever the debug setting, reporting neither', function () {
    config(['app.debug' => true]);

    $this->call('POST', centralUrl('/webhooks/nowhere/at/all'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'not_found', 'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')]]);
    $this->call('GET', centralUrl('/webhooks/billing'))
        ->assertStatus(405)
        ->assertExactJson(['code' => 'method_not_allowed', 'message' => 'method_not_allowed', 'data' => ['status' => 405, 'detail' => apiSentence('api.status.method_not_allowed')]]);

    expect($this->log->records)->toBe([]);
});

it('hands the seam the revision the row was read at', function () {
    $seam = new class implements CheckoutCompletion
    {
        public ?int $revision = null;

        public function completed(string $customerId, string $subscriptionId, int $revision): ?SyncOutcome
        {
            $this->revision = $revision;

            return null;
        }
    };
    app()->instance(CheckoutCompletion::class, $seam);
    billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'active', ['price_floor_test']));
    syncWith()->refresh('sub_luna');

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_luna'))->assertOk();

    expect($seam->revision)->toBe(DB::table('subscriptions')->where('provider_customer_id', 'cus_luna')->value('revision'))
        ->and($seam->revision)->toBe(1);
});

it('leaves a completed checkout unmarked, for the provider to deliver again, when the seam\'s sync could not resolve', function () {
    $seam = new RecordingCompletion(SyncOutcome::Unresolved);
    app()->instance(CheckoutCompletion::class, $seam);

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_luna'))->assertStatus(500);

    expect($seam->completed)->toBe([['cus_luna', 'sub_luna']])
        ->and(billingWebhookEvent('evt_1')['processed_at'])->toBeNull();
});

it('asks the seam again from the row while the row keeps moving, then leaves the event for the provider', function () {
    $seam = new RecordingCompletion(SyncOutcome::Stale);
    app()->instance(CheckoutCompletion::class, $seam);

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::CheckoutCompleted, 'cus_luna', 'sub_luna'))->assertStatus(500);

    expect($seam->completed)->toHaveCount(BillingWebhook::ATTEMPTS)
        ->and(billingWebhookEvent('evt_1')['processed_at'])->toBeNull()
        ->and($this->log->records[0]['context']['message'] ?? null)->toBe(NoticeUnhandled::contended()->getMessage());
});

it('logs a status this build does not know once for the delivery, not once per pass, while the row moves twice as the event is handled', function () {
    $fake = billingFake()->script(billingSnapshot('sub_luna', 'cus_luna', 'on_holiday', ['price_staff_test']));
    $moves = 2;
    // Another write landing while the provider is asked, twice: the first two passes answer Stale.
    BillingPortDecorator::around($fake, beforeRead: function () use (&$moves): void {
        if ($moves-- > 0) {
            DB::table('subscriptions')->update(['revision' => DB::raw('revision + 1')]);
        }
    });

    deliverBillingNotice($this, FakeBillingProvider::notice('evt_1', NoticeKind::SubscriptionChanged, 'cus_luna', 'sub_luna'))->assertOk();

    expect(array_column($fake->asked, 0))->toBe(['verify', 'subscription', 'subscription', 'subscription'])
        ->and(billingColumns())->toMatchArray(['subscription_state' => 'active', 'entitlements' => '{staff}'])
        ->and(subscriptionColumns()['status'])->toBe('on_holiday')
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => 'The billing provider reported a subscription status this build does not know; the stored state is kept',
            'context' => ['tenant' => 'luna', 'status' => 'on_holiday'],
        ]]);
});
