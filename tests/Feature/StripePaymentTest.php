<?php

declare(strict_types=1);

use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\NewConnection;
use App\Application\Payment\PaymentNote;
use App\Application\Payment\ProviderRegistry;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Application\Payment\Stripe\StripeUnreachable;
use App\Infrastructure\Payment\LoggedPayments;
use App\Infrastructure\Payment\Stripe\HttpStripeTransport;
use App\Tenancy\Tenant;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SeamLog;
use Tests\TestCase;

/*
 * The paid path once more with the real Stripe adapter, in both connection modes: luna connected
 * to Stripe with its own credentials, or through the platform's partner account and a connected
 * account, the platform's fee configured as 1.5% plus EUR 0.25. The adapter's transport is the
 * real one over the framework's client faked with stray requests refused, so nothing leaves the
 * process and every header Stripe would read is read here. On the system clock with every date
 * relative to today, since the adapter's clock, the hold's liveness and the signature's
 * timestamp must agree. Not wrapped in a transaction: the hold, the paid transition and the
 * cancel enter the transaction runner.
 */

pest()->use(DatabaseTruncation::class);

const PAY_STRIPE_E2E_KEY = 'sk_test_51EndToEndSecretNeverShown';
const PAY_STRIPE_E2E_SIGNING = 'whsec_EndToEndSigningNeverShown';
const PAY_STRIPE_E2E_PLATFORM_KEY = 'sk_test_51EndToEndPlatformKeyNeverShown';
const PAY_STRIPE_E2E_PLATFORM_SIGNING = 'whsec_EndToEndPlatformSigningNeverShown';
const PAY_STRIPE_E2E_ACCOUNT = 'acct_1E2eLunaConnected';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    Http::preventStrayRequests();
    seamTransport()->flush();
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    config([
        'payments.platform.stripe' => [StripeAdapter::SECRET_KEY => PAY_STRIPE_E2E_PLATFORM_KEY, StripeAdapter::WEBHOOK_SECRET => PAY_STRIPE_E2E_PLATFORM_SIGNING],
        'payments.platform_fee' => ['percent' => '1.5', 'fixed' => 'EUR:25'],
    ]);
    payStripeConnect('own');
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * luna connected to Stripe in the mode named, written through the store as the Payments section
 * writes it: own, with its own key and signing secret and the token's webhook; platform, active
 * on its connected account with no credential of its own, its notifications posted to the
 * platform's endpoint and signed with the platform's secret. Answers the key every request to
 * Stripe must carry, the endpoint, the secret a notification is signed with and the connected
 * account, null in own mode.
 *
 * @return array{key: string, endpoint: string, signing: string, account: string|null}
 */
function payStripeConnect(string $mode): array
{
    if ($mode === 'own') {
        paymentConnections('luna')->write(new NewConnection('stripe', ConnectionMode::Own, ConnectionState::Active, [StripeAdapter::SECRET_KEY => PAY_STRIPE_E2E_KEY, StripeAdapter::WEBHOOK_SECRET => PAY_STRIPE_E2E_SIGNING], null, 'hook-token-luna'));

        return ['key' => PAY_STRIPE_E2E_KEY, 'endpoint' => '/webhooks/stripe/hook-token-luna', 'signing' => PAY_STRIPE_E2E_SIGNING, 'account' => null];
    }

    paymentConnections('luna')->write(new NewConnection('stripe', ConnectionMode::Platform, ConnectionState::Active, null, PAY_STRIPE_E2E_ACCOUNT));

    return ['key' => PAY_STRIPE_E2E_PLATFORM_KEY, 'endpoint' => '/webhooks/stripe', 'signing' => PAY_STRIPE_E2E_PLATFORM_SIGNING, 'account' => PAY_STRIPE_E2E_ACCOUNT];
}

/**
 * A Colour hold, EUR 80.00, on next week's Tuesday with Elena, at the hour given.
 *
 * @return array{uuid: string, manage_token: string}
 */
function payStripeHold(TestCase $test, int $hour = 10, string $email = 'casey@example.com'): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => $email,
        'items' => [['service_id' => $ids('services', 'Colour'), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => firstWindowDay(2)->setTime($hour, 0)->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token']];
}

/**
 * The customer pressing the pay hop.
 *
 * @param  array{uuid: string, manage_token: string}  $held
 * @return TestResponse<Response>
 */
function payStripeHop(TestCase $test, array $held): TestResponse
{
    return $test->get(tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $held['manage_token']])));
}

/**
 * Stripe's answer to a session create, echoing what the request asked as Stripe does, under the
 * id given. The client keeps the form as the adapter flattened it, bracketed names and all.
 *
 * @return array<string, mixed>
 */
function payStripeCreated(Request $request, string $id = 'cs_test_e2e'): array
{
    $form = $request->data();

    return [
        'id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
        'url' => 'https://checkout.stripe.com/c/pay/'.$id, 'expires_at' => $form['expires_at'] ?? null,
        'metadata' => ['booking' => $form['metadata[booking]'] ?? null], 'client_reference_id' => $form['client_reference_id'] ?? null, 'payment_intent' => null,
    ];
}

/**
 * Stripe's checkout.session.completed for the booking's session, paid by pi_e2e, EUR 80.00; a
 * connected account's event names the account at its top level, as Connect delivers it.
 */
function payStripePaidEvent(string $uuid, ?string $account, string $event = 'evt_e2e'): string
{
    return (string) json_encode(['id' => $event, 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false, ...($account === null ? [] : ['account' => $account]), 'data' => ['object' => [
        'id' => 'cs_test_e2e', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_e2e', 'metadata' => ['booking' => $uuid],
        'amount_total' => 8000, 'currency' => 'eur',
    ]]]);
}

/**
 * The body posted to the connection's endpoint, signed now in Stripe's scheme with the secret
 * given, the connection's own by default.
 *
 * @param  array{key: string, endpoint: string, signing: string, account: string|null}  $connected
 * @return TestResponse<Response>
 */
function payStripeNotify(TestCase $test, #[SensitiveParameter] array $connected, string $body, #[SensitiveParameter] ?string $secret = null): TestResponse
{
    $at = time();

    return $test->call('POST', centralUrl($connected['endpoint']), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.'.$body, $secret ?? $connected['signing'])),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * Every request Stripe was asked, as [method, path, the connected account it named, the fee it
 * attached, whether it carried the key given].
 *
 * @return list<array{string, string, array<array-key, mixed>, mixed, bool}>
 */
function payStripeAsked(#[SensitiveParameter] string $key): array
{
    return array_values(Http::recorded()->map(fn (array $pair): array => [
        $pair[0]->method(),
        (string) parse_url($pair[0]->url(), PHP_URL_PATH),
        $pair[0]->header('Stripe-Account'),
        $pair[0]->data()['payment_intent_data[application_fee_amount]'] ?? null,
        $pair[0]->hasHeader('Authorization', 'Bearer '.$key),
    ])->all());
}

function payStripeStatus(string $uuid): mixed
{
    return DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status');
}

/**
 * How many confirmations Casey was sent.
 */
function payStripeConfirmations(): int
{
    return count(array_filter(seamSent(), fn ($mail): bool => $mail->getSubject() === customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio'])));
}

it('runs the paid path end to end with the real adapter in either mode: the hop opens a Stripe session, a signed completion on the mode\'s endpoint confirms the booking, the customer never returns', function (string $mode) {
    $connected = payStripeConnect($mode);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => fn (Request $request): PromiseInterface => Http::response(payStripeCreated($request))]);
    $held = payStripeHold($this);
    $holdEnd = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('hold_expires_at');

    payStripeHop($this, $held)->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e');

    $row = (array) DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->first();
    $sent = Http::recorded()[0][0] ?? null;

    assert($sent instanceof Request);

    // The customer is asked the total alone in both modes; through the platform the request
    // acts on the connected account and attaches the fee, 1.5% of 8000 is 120, plus 25.
    expect($row['payment_ref'])->toBe('cs_test_e2e')
        ->and($sent->hasHeader('Authorization', 'Bearer '.$connected['key']))->toBeTrue()
        ->and($sent->header('Stripe-Account'))->toBe($connected['account'] === null ? [] : [$connected['account']])
        ->and($sent->header('Idempotency-Key')[0] ?? '')->toStartWith('checkout-')
        ->and($sent->data())->toMatchArray(['mode' => 'payment', 'client_reference_id' => $held['uuid'], 'metadata[booking]' => $held['uuid'], 'line_items[0][price_data][unit_amount]' => 8000])
        ->and($sent->data()['payment_intent_data[application_fee_amount]'] ?? null)->toBe($connected['account'] === null ? null : 145)
        ->and(is_string($row['hold_expires_at']) ? (new DateTimeImmutable($row['hold_expires_at'], new DateTimeZone('UTC')))->getTimestamp() : null)->toBe($sent->data()['expires_at'] ?? false)
        // The session ends at the hold's end plus Stripe's thirty-minute floor, one session per
        // hold, and the hop writes that end back as the hold's.
        ->and($sent->data()['expires_at'] ?? null)->toBe(is_string($holdEnd) ? (new DateTimeImmutable($holdEnd, new DateTimeZone('UTC')))->getTimestamp() + 1800 : false);

    payStripeNotify($this, $connected, payStripePaidEvent($held['uuid'], $connected['account']))->assertOk();

    $row = (array) DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->first();

    $trail = DB::table('tenant_luna.audit_log')->where('booking_id', $row['id'])->where('action', 'confirmed')->value('payload');
    $trail = is_string($trail) ? json_decode($trail, true) : null;

    expect([$row['status'], $row['hold_expires_at'], $row['payment_ref']])->toBe(['confirmed', null, 'cs_test_e2e'])
        // The paid transition's trail row records what the session took, beside payment_named.
        ->and(is_array($trail) ? [$trail['payment_named'] ?? null, $trail['paid_minor'] ?? null, $trail['paid_currency'] ?? null] : null)->toBe([true, 8000, 'EUR'])
        ->and(seamAt('casey@example.com')->getSubject())->toBe(customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and(Http::recorded())->toHaveCount(1)
        ->and(DB::table('payment_events')->whereNotNull('processed_at')->get(['route', 'tenant_id', 'event_id'])->map(fn (object $event): array => (array) $event)->all())
        ->toBe([['route' => $mode, 'tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'), 'event_id' => 'evt_e2e']])
        ->and($this->log->text())->not->toContain(PAY_STRIPE_E2E_KEY)->not->toContain(PAY_STRIPE_E2E_SIGNING)
        ->not->toContain(PAY_STRIPE_E2E_PLATFORM_KEY)->not->toContain(PAY_STRIPE_E2E_PLATFORM_SIGNING);
})->with(['own', 'platform']);

it('confirms a booking paid through a platform connection on the platform endpoint, its tenant found by the account, once however often it arrives, and refuses and records a bad signature', function () {
    $connected = payStripeConnect('platform');
    Http::fake(['api.stripe.com/v1/checkout/sessions' => fn (Request $request): PromiseInterface => Http::response(payStripeCreated($request))]);
    $held = payStripeHold($this);
    payStripeHop($this, $held)->assertStatus(303);
    $before = payStripeStatus($held['uuid']);
    $body = payStripePaidEvent($held['uuid'], PAY_STRIPE_E2E_ACCOUNT);

    payStripeNotify($this, $connected, $body, 'whsec_forged')->assertStatus(400)
        ->assertJsonPath('code', 'invalid_signature')->assertJsonPath('data.status', 400)
        ->assertJsonPath('data.detail', 'The notification does not carry a signature the payment provider vouches for.');

    expect(payStripeStatus($held['uuid']))->toBe($before)
        ->and(DB::table('payment_events')->get(['provider', 'route', 'tenant_id', 'event_id', 'refusal'])->map(fn (object $event): array => (array) $event)->all())
        ->toBe([['provider' => 'stripe', 'route' => 'platform', 'tenant_id' => null, 'event_id' => null, 'refusal' => 'signature']]);

    // The same payment named on an account no connection holds reaches no tenant.
    payStripeNotify($this, $connected, payStripePaidEvent($held['uuid'], 'acct_1E2eNobodyHoldsThis', 'evt_elsewhere'))->assertOk()->assertContent('');

    expect(payStripeStatus($held['uuid']))->toBe($before)
        ->and(DB::table('payment_events')->count())->toBe(1);

    payStripeNotify($this, $connected, $body)->assertOk();
    payStripeNotify($this, $connected, $body)->assertOk();

    expect(payStripeStatus($held['uuid']))->toBe('confirmed')
        ->and($before)->not->toBe('confirmed')
        ->and(payStripeConfirmations())->toBe(1)
        ->and(DB::table('payment_events')->whereNotNull('event_id')->get(['route', 'tenant_id', 'event_id'])->map(fn (object $event): array => (array) $event)->all())
        ->toBe([['route' => 'platform', 'tenant_id' => Tenant::query()->where('slug', 'luna')->value('id'), 'event_id' => 'evt_e2e']])
        ->and(Http::recorded())->toHaveCount(1)
        ->and($this->log->text())->not->toContain(PAY_STRIPE_E2E_PLATFORM_KEY)->not->toContain(PAY_STRIPE_E2E_PLATFORM_SIGNING);
});

it('keeps the fee a platform session was opened with when the fee changes in configuration, reading the session back and creating nothing, and charges a booking opened afterwards the new fee', function () {
    payStripeConnect('platform');
    $sessions = [];
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => function (Request $request) use (&$sessions): PromiseInterface {
            $id = 'cs_test_e2e_'.(count($sessions) + 1);
            $sessions[$id] = payStripeCreated($request, $id);

            return Http::response($sessions[$id]);
        },
        'api.stripe.com/v1/checkout/sessions/*' => function (Request $request) use (&$sessions): PromiseInterface {
            $session = $sessions[basename((string) parse_url($request->url(), PHP_URL_PATH))] ?? null;

            return $session === null ? Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'No such checkout.session']], 404) : Http::response($session);
        },
    ]);
    $first = payStripeHold($this);

    payStripeHop($this, $first)->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e_1');

    config(['payments.platform_fee.percent' => '2.5']);

    payStripeHop($this, $first)->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e_1');

    $second = payStripeHold($this, 14, 'robin@example.com');

    payStripeHop($this, $second)->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e_2');

    // 2.5% of 8000 is 200, plus 25: the new fee on the new session alone.
    expect(payStripeAsked(PAY_STRIPE_E2E_PLATFORM_KEY))->toBe([
        ['POST', '/v1/checkout/sessions', [PAY_STRIPE_E2E_ACCOUNT], 145, true],
        ['GET', '/v1/checkout/sessions/cs_test_e2e_1', [PAY_STRIPE_E2E_ACCOUNT], null, true],
        ['POST', '/v1/checkout/sessions', [PAY_STRIPE_E2E_ACCOUNT], 225, true],
    ])
        ->and(DB::table('tenant_luna.bookings')->whereIn('uuid', [$first['uuid'], $second['uuid']])->orderBy('payment_ref')->pluck('payment_ref')->all())->toBe(['cs_test_e2e_1', 'cs_test_e2e_2']);
});

it('flags the payment of a cancelled booking paid through a platform connection with the note, on the connected account under the platform\'s key', function () {
    $connected = payStripeConnect('platform');
    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => fn (Request $request): PromiseInterface => Http::response(payStripeCreated($request)),
        'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_test_e2e', 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_e2e']),
        'api.stripe.com/v1/payment_intents/*' => Http::response(['id' => 'pi_e2e', 'object' => 'payment_intent']),
    ]);
    $held = payStripeHold($this);
    payStripeHop($this, $held)->assertStatus(303);
    payStripeNotify($this, $connected, payStripePaidEvent($held['uuid'], PAY_STRIPE_E2E_ACCOUNT))->assertOk();

    expect(payStripeStatus($held['uuid']))->toBe('confirmed');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/cancel'), ['token' => $held['manage_token']])->assertSuccessful();

    $noted = Http::recorded()[2][0] ?? null;

    assert($noted instanceof Request);

    expect(payStripeStatus($held['uuid']))->toBe('cancelled')
        ->and(payStripeAsked(PAY_STRIPE_E2E_PLATFORM_KEY))->toBe([
            ['POST', '/v1/checkout/sessions', [PAY_STRIPE_E2E_ACCOUNT], 145, true],
            ['GET', '/v1/checkout/sessions/cs_test_e2e', [PAY_STRIPE_E2E_ACCOUNT], null, true],
            ['POST', '/v1/payment_intents/pi_e2e', [PAY_STRIPE_E2E_ACCOUNT], null, true],
        ])
        ->and($noted->data())->toBe(['description' => PaymentNote::cancelled($held['uuid']), 'metadata[note]' => PaymentNote::cancelled($held['uuid'])])
        ->and($this->log->text())->not->toContain(PAY_STRIPE_E2E_PLATFORM_KEY);
});

it('keeps taking a platform connection\'s payments with the fee unset after it was made, sending no fee, and logs one error line per session naming the condition and no secret; a log that cannot be written fails no checkout', function () {
    config(['payments.platform_fee' => ['percent' => null, 'fixed' => null]]);
    app()->forgetInstance(ProviderRegistry::class);
    app()->forgetInstance(StripeAdapter::class);
    $connected = payStripeConnect('platform');
    Http::fake(['api.stripe.com/v1/checkout/sessions' => fn (Request $request): PromiseInterface => Http::response(payStripeCreated($request, 'cs_test_e2e_'.Http::recorded()->count()))]);
    $first = payStripeHold($this);
    $second = payStripeHold($this, 14, 'robin@example.com');

    payStripeHop($this, $first)->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e_0');
    $this->log->refuses = LoggedPayments::FEE_UNSET;
    payStripeHop($this, $second)->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_e2e_1');

    expect(payStripeAsked($connected['key']))->toBe([
        ['POST', '/v1/checkout/sessions', [PAY_STRIPE_E2E_ACCOUNT], null, true],
        ['POST', '/v1/checkout/sessions', [PAY_STRIPE_E2E_ACCOUNT], null, true],
    ])
        ->and(array_map(fn (array $record): array => [$record['level'], $record['message'], $record['context']], array_values(array_filter($this->log->records, fn (array $record): bool => $record['message'] === LoggedPayments::FEE_UNSET))))
        ->toBe([['error', LoggedPayments::FEE_UNSET, []], ['error', LoggedPayments::FEE_UNSET, []]])
        ->and($this->log->text())->not->toContain(PAY_STRIPE_E2E_PLATFORM_KEY)->not->toContain(PAY_STRIPE_E2E_PLATFORM_SIGNING);
});

it('answers the hop\'s worded 502 where Stripe refuses the key, recording nothing, and the report carries no secret though Stripe echoed it', function () {
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: '.PAY_STRIPE_E2E_KEY]], 401)]);
    $held = payStripeHold($this);

    $this->get(tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $held['manage_token']])))
        ->assertStatus(502)->assertSee(pageSentence('pages.pay.unopened.title'));

    expect(DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('payment_ref'))->toBeNull()
        ->and($this->log->records)->not->toBe([])
        ->and($this->log->text())->not->toContain(PAY_STRIPE_E2E_KEY)->not->toContain(PAY_STRIPE_E2E_SIGNING);
});

it('refuses a notification signed with another secret and keeps none of it, and no secret reaches the log', function () {
    $body = (string) json_encode(['id' => 'evt_forged', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_e2e', 'object' => 'checkout.session', 'payment_status' => 'paid', 'metadata' => ['booking' => 'x']]]]);
    $at = time();

    $this->call('POST', centralUrl('/webhooks/stripe/hook-token-luna'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.'.$body, 'whsec_forged')),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(400)->assertJsonPath('code', 'invalid_signature');

    expect($this->log->text())->not->toContain(PAY_STRIPE_E2E_SIGNING);
});

it('sends every request as a form with the key as its bearer, inside a timeout well under the queue\'s retry_after, and turns a connection failure into a fixed sentence that wraps nothing', function () {
    Http::fake(['api.stripe.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out; Authorization: Bearer '.PAY_STRIPE_E2E_KEY)]);

    try {
        app(HttpStripeTransport::class)->send('POST', '/v1/checkout/sessions/cs_test_e2e/expire', [], PAY_STRIPE_E2E_KEY);
        $failure = null;
    } catch (StripeUnreachable $thrown) {
        $failure = $thrown;
    }

    expect($failure?->getMessage())->toBe('Stripe could not be reached.')
        ->and($failure?->getPrevious())->toBeNull()
        ->and(HttpStripeTransport::TIMEOUT * 2)->toBeLessThan(90);
});
