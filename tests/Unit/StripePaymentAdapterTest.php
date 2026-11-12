<?php

declare(strict_types=1);

use App\Application\Dto\BookingSnapshot;
use App\Application\Payment\AccountStanding;
use App\Application\Payment\CheckoutRequest;
use App\Application\Payment\CheckoutSession;
use App\Application\Payment\ConnectionCheck;
use App\Application\Payment\ConnectionMode;
use App\Application\Payment\ConnectionState;
use App\Application\Payment\Credentials;
use App\Application\Payment\NewConnection;
use App\Application\Payment\NotificationRefused;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\PaymentAdapter;
use App\Application\Payment\PaymentConnection;
use App\Application\Payment\PaymentConnections;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\PlatformFee;
use App\Application\Payment\SessionWithheld;
use App\Application\Payment\Stripe\StripeAdapter;
use App\Application\Payment\Stripe\StripeFailed;
use App\Application\Payment\Stripe\StripePaymentProvider;
use App\Application\Payment\Stripe\StripeUnreachable;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use Tests\Support\FakeStripePaymentTransport;
use Tests\Support\FixedClock;
use Tests\Support\FixedPlatform;
use Tests\Support\RecordingPaymentLog;
use Tests\Support\SnapshotFixture;

/*
 * The Stripe payment adapter over a fake transport: every request it sends and every answer it
 * reads is a documented shape, and the transport refuses any request a case did not script.
 * Nothing here opens a connection.
 */

const PAY_STRIPE_KEY = 'sk_test_51PmSecretKeyNeverShown';
const PAY_STRIPE_SIGNING = 'whsec_SigningSecretNeverShown';
const PAY_STRIPE_NOW = '2026-11-01 09:00:00';

function payStripeStore(bool $lends = true, bool $keyStored = true): PayStripeStore
{
    return new PayStripeStore($lends, $keyStored);
}

/**
 * The tenant's store as the adapter meets it: one own-mode Stripe connection whose door lends
 * the two secrets, or lends nothing, and counts how often it was opened.
 */
final class PayStripeStore implements PaymentConnections
{
    public int $opened = 0;

    public function __construct(private bool $lends, private bool $keyStored) {}

    public function primary(): PaymentConnection
    {
        return payStripeConnection();
    }

    public function write(NewConnection $connection): void {}

    public function recordCheck(bool $ok, DateTimeImmutable $at): void {}

    public function landCheck(ConnectionCheck $check, DateTimeImmutable $at): bool
    {
        return false;
    }

    public function landStanding(string $accountRef, AccountStanding $standing, DateTimeImmutable $at): bool
    {
        return false;
    }

    public function setEnabled(bool $enabled): bool
    {
        return false;
    }

    public function rotateToken(string $token): bool
    {
        return false;
    }

    public function remove(): bool
    {
        return false;
    }

    public function webhookToken(): ?string
    {
        return null;
    }

    public function withCredentials(PaymentAdapter $adapter, Closure $call): mixed
    {
        $this->opened++;

        return $this->lends ? $call(new Credentials([StripeAdapter::SECRET_KEY => $this->keyStored ? PAY_STRIPE_KEY : '', StripeAdapter::WEBHOOK_SECRET => PAY_STRIPE_SIGNING])) : null;
    }
}

function payStripeConnection(ConnectionMode $mode = ConnectionMode::Own): PaymentConnection
{
    return new PaymentConnection('stripe', $mode, ConnectionState::Active, null, null, null, null, true);
}

function payStripeAdapter(FakeStripePaymentTransport $transport, ?FixedPlatform $platform = null, ?RecordingPaymentLog $log = null): StripeAdapter
{
    return new StripeAdapter($transport, FixedClock::at(PAY_STRIPE_NOW), $platform ?? new FixedPlatform, $log ?? new RecordingPaymentLog);
}

function payStripeProvider(FakeStripePaymentTransport $transport, ?PaymentConnections $store = null): PaymentProvider
{
    return payStripeAdapter($transport)->provider(payStripeConnection(), $store ?? payStripeStore());
}

function payStripeNow(int $plusSeconds = 0): DateTimeImmutable
{
    return (new DateTimeImmutable(PAY_STRIPE_NOW, new DateTimeZone('UTC')))->add(new DateInterval(sprintf('PT%dS', $plusSeconds)));
}

function payStripeBooking(?Money $total = null): BookingSnapshot
{
    $booking = SnapshotFixture::booking();

    return new BookingSnapshot($booking->uuid, $booking->status, $booking->holdClass, $booking->holdExpiresAt, $booking->customer, $total ?? $booking->total, $booking->items);
}

function payStripeRequest(?string $ref = null, int $holdLeft = 840, ?Money $total = null, int $sessionsCleared = 0): CheckoutRequest
{
    return new CheckoutRequest(payStripeBooking($total), $ref, 'https://luna.test/manage/x?token=t0k', payStripeNow($holdLeft), $sessionsCleared);
}

/**
 * A Checkout Session as Stripe's API reference spells the object, trimmed to what the adapter reads beside a few it does not.
 *
 * @param  array<array-key, mixed>  $over
 * @return array<array-key, mixed>
 */
function payStripeSession(array $over = []): array
{
    return [
        'id' => 'cs_test_a1B2c3', 'object' => 'checkout.session', 'mode' => 'payment', 'currency' => 'eur', 'amount_total' => 4500,
        'client_reference_id' => payStripeBooking()->uuid, 'metadata' => ['booking' => payStripeBooking()->uuid], 'payment_intent' => null,
        'payment_status' => 'unpaid', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3', 'expires_at' => payStripeNow(1920)->getTimestamp(),
        ...$over,
    ];
}

/**
 * An event as Stripe delivers it, its signature header computed as Stripe's webhook guide says;
 * a connected account's event, as a Connect endpoint receives it, names the account at its top.
 *
 * @param  array<array-key, mixed>  $object
 * @return array{string, string}
 */
function payStripeEvent(string $type, array $object, int $signedAgo = 0, #[SensitiveParameter] string $secret = PAY_STRIPE_SIGNING, ?string $account = null): array
{
    $connect = $account === null ? [] : ['account' => $account];
    $body = (string) json_encode(['id' => 'evt_1Q2w3E', 'object' => 'event', ...$connect, 'type' => $type, 'created' => payStripeNow()->getTimestamp(), 'livemode' => false, 'data' => ['object' => $object]]);
    $at = payStripeNow(0)->getTimestamp() - $signedAgo;

    return [$body, sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.'.$body, $secret))];
}

function payStripeCredentials(): Credentials
{
    return new Credentials([StripeAdapter::SECRET_KEY => PAY_STRIPE_KEY, StripeAdapter::WEBHOOK_SECRET => PAY_STRIPE_SIGNING]);
}

/**
 * Whether the secrets appear anywhere a failure carries text: its message, what it wraps, and
 * every argument its trace holds in a frame of the application's or of the transport's (the
 * test's own frames hold the secret it searches for).
 */
function payStripeSecretsIn(Throwable $failure): bool
{
    $frames = array_filter($failure->getTrace(), fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'App\\') || str_starts_with($frame['class'] ?? '', 'Tests\\Support\\'));
    $text = $failure->getMessage().print_r($frames, true).(string) $failure->getPrevious();

    return str_contains($text, PAY_STRIPE_KEY) || str_contains($text, PAY_STRIPE_SIGNING);
}

it('opens a hosted Checkout Session priced inline from the booking\'s own total and currency, naming the booking, returning to the caller\'s address, with the secret key as its bearer', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession(['expires_at' => payStripeNow(1925)->getTimestamp()]));
    $request = payStripeRequest();
    $session = payStripeProvider($transport)->checkoutUrl($request);
    $sent = $transport->sent[0] ?? null;

    assert($session instanceof CheckoutSession && $sent !== null);

    expect([$sent['method'], $sent['path'], $sent['keyMatched']])->toBe(['POST', '/v1/checkout/sessions', true])
        ->and($sent['form'])->toMatchArray([
            'mode' => 'payment',
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($request->booking->total->currency->code),
            'line_items[0][price_data][unit_amount]' => $request->booking->total->minor,
            'client_reference_id' => $request->booking->uuid,
            'metadata[booking]' => $request->booking->uuid,
            'payment_intent_data[metadata][booking]' => $request->booking->uuid,
            'success_url' => 'https://luna.test/manage/x?token=t0k',
            'cancel_url' => 'https://luna.test/manage/x?token=t0k',
        ])
        ->and($sent['form'])->not->toHaveKey('line_items[0][price]')
        ->and([$session->url, $session->ref])->toBe(['https://checkout.stripe.com/c/pay/cs_test_a1B2c3', 'cs_test_a1B2c3'])
        ->and($session->endsAt->getTimestamp())->toBe(payStripeNow(1925)->getTimestamp())
        ->and($session->endsAt->getTimezone()->getName())->toBe('UTC');
});

it('sends a currency with no minor unit at its own scale: five thousand yen is 5000, not 500000', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    payStripeProvider($transport)->checkoutUrl(payStripeRequest(total: new Money(5000, Currency::of('JPY'))));

    expect($transport->sent[0]['form'] ?? [])->toMatchArray(['line_items[0][price_data][currency]' => 'jpy', 'line_items[0][price_data][unit_amount]' => 5000]);
});

it('asks for a session ending at the hold\'s end plus Stripe\'s thirty-minute floor, one end per hold whatever the clock, and takes the twenty-four-hour ceiling where that end passes it, by whole minutes to just inside it; a later session, after a move expired the first, ends with the hold as it stands', function (int $holdLeft, int $sentAfterNow, bool $holdFollowsSession = false) {
    expect(StripePaymentProvider::sessionEnd(payStripeNow($holdLeft), payStripeNow(), $holdFollowsSession) - payStripeNow()->getTimestamp())->toBe($sentAfterNow);
})->with([
    'a fifteen-minute hold, its end plus the floor' => [840, 2640],
    'a hold of a second, its end plus the floor' => [1, 1801],
    'a two-hour hold, its end plus the floor' => [7200, 9000],
    'a hold whose end plus the floor falls just inside the ceiling, kept to the second' => [84540, 86340],
    'a payment link\'s twenty-four-hour hold, whose end plus the floor passes the ceiling, less a minute of margin' => [86400, 86340],
    'a hold two days long, shortened inside the ceiling, less a minute of margin' => [172800, 86340],
    'a later session on the hold the first lengthened, its end as it stands and no floor again' => [2640, 2640, true],
    'a later session on a hold with the floor and the margin left exactly, its end as it stands' => [1860, 1860, true],
    'a later session on a hold the ceiling shortened at its first, its end as it stands' => [86340, 86340, true],
]);

it('ends every session after a move expired the hold\'s first with the hold as it stands, each keyed by the sessions the moves cleared, so a retry meets the session of its own cycle and never one a move expired, and the hold is lengthened no further', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY));

    foreach (range(1, 4) as $answer) {
        $transport->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    }

    // The first session lengthened the hold from 840 to 2640 seconds out; two moves since each
    // cleared the session the hop recorded, and a retry of the second cycle inside it.
    payStripeProvider($transport)->checkoutUrl(payStripeRequest());
    payStripeProvider($transport)->checkoutUrl(payStripeRequest(holdLeft: 2640, sessionsCleared: 1));
    payStripeProvider($transport)->checkoutUrl(payStripeRequest(holdLeft: 2640, sessionsCleared: 2));
    payStripeProvider($transport)->checkoutUrl(payStripeRequest(holdLeft: 2640, sessionsCleared: 1));
    $keys = array_column($transport->sent, 'idempotencyKey');
    $ends = array_map(fn (array $form): mixed => $form['expires_at'] ?? null, array_column($transport->sent, 'form'));
    $booking = payStripeRequest()->booking;
    $later = [$booking->uuid, payStripeNow(2640)->getTimestamp(), $booking->total->minor, $booking->total->currency->code, payStripeNow(2640)->getTimestamp()];

    expect($ends)->toBe(array_fill(0, 4, payStripeNow(2640)->getTimestamp()))
        ->and(count(array_unique($keys)))->toBe(3)
        ->and($keys[3])->toBe($keys[1])
        ->and($keys[1])->toBe('checkout-'.hash('sha256', implode('|', [...$later, 1])))
        ->and($keys[2])->toBe('checkout-'.hash('sha256', implode('|', [...$later, 2])));
});

it('answers a later session on a hold with less than the floor and the margin left as too short, before Stripe is asked, since Stripe would refuse the end and the hold may be lengthened no further: told apart from a provider that answered nowhere to go', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    $store = payStripeStore();

    $refused = payStripeAdapter($transport)->provider(payStripeConnection(), $store)->checkoutUrl(payStripeRequest(holdLeft: 1859, sessionsCleared: 1));
    $opened = payStripeAdapter($transport)->provider(payStripeConnection(), $store)->checkoutUrl(payStripeRequest(holdLeft: 1860, sessionsCleared: 1));

    expect($refused)->toBe(SessionWithheld::TooShort)
        ->and($opened)->toBeInstanceOf(CheckoutSession::class)
        ->and(count($transport->sent))->toBe(1)
        ->and($store->opened)->toBe(1);
});

it('sends one end and one key for one hold whatever the clock reads when the session is opened, so a retry minutes later meets Stripe\'s first session; a moved hold is another key', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    $later = new StripeAdapter($transport, FixedClock::at('2026-11-01 09:13:59'), new FixedPlatform, new RecordingPaymentLog);
    payStripeProvider($transport)->checkoutUrl(payStripeRequest());
    $later->provider(payStripeConnection(), payStripeStore())->checkoutUrl(payStripeRequest());
    payStripeProvider($transport)->checkoutUrl(payStripeRequest(holdLeft: 600));
    $keys = array_column($transport->sent, 'idempotencyKey');
    $ends = array_map(fn (array $form): mixed => $form['expires_at'] ?? null, array_column($transport->sent, 'form'));

    expect($keys[0])->toBeString()->toStartWith('checkout-')
        ->and($keys[1])->toBe($keys[0])
        ->and($keys[2])->not->toBe($keys[0])
        ->and($ends)->toBe([payStripeNow(840 + 1800)->getTimestamp(), payStripeNow(840 + 1800)->getTimestamp(), payStripeNow(600 + 1800)->getTimestamp()]);
});

it('reads a recorded session back and answers it while open, creating nothing; one Stripe completed, paid or still processing its payment, answers that the payment is on its way; one expired, or another booking\'s, answers no page', function (array $over, string|SessionWithheld|null $answered) {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession($over));
    $answer = payStripeProvider($transport)->checkoutUrl(payStripeRequest('cs_test_a1B2c3'));

    expect($answer instanceof CheckoutSession ? $answer->ref : $answer)->toBe($answered)
        ->and(array_column($transport->sent, 'method'))->toBe(['GET']);
})->with([
    'open' => [[], 'cs_test_a1B2c3'],
    'complete and paid' => [['status' => 'complete', 'payment_status' => 'paid', 'url' => null], SessionWithheld::Completed],
    'complete, its payment still processing' => [['status' => 'complete', 'payment_status' => 'unpaid', 'url' => null], SessionWithheld::Completed],
    'expired' => [['status' => 'expired', 'url' => null], null],
    'another booking\'s' => [['metadata' => ['booking' => 'another']], null],
    'another booking\'s, complete' => [['status' => 'complete', 'payment_status' => 'paid', 'url' => null, 'metadata' => ['booking' => 'another']], null],
    'another session' => [['id' => 'cs_test_other'], null],
    'another session, complete' => [['id' => 'cs_test_other', 'status' => 'complete', 'payment_status' => 'paid', 'url' => null], null],
]);

it('answers no page for a hold already past its end, before Stripe is asked', function () {
    $transport = new FakeStripePaymentTransport(PAY_STRIPE_KEY);

    expect(payStripeProvider($transport)->checkoutUrl(payStripeRequest(holdLeft: 0)))->toBeNull()
        ->and($transport->sent)->toBe([]);
});

it('neither opens the transport nor borrows a credential to build its port or to answer isAvailable, both of which run under the staff-day lock', function () {
    $transport = new FakeStripePaymentTransport;
    $store = payStripeStore();
    $provider = payStripeAdapter($transport)->provider(payStripeConnection(), $store);

    expect($provider->isAvailable())->toBeTrue()
        ->and($provider->paymentRef(payStripeBooking()))->toBeNull()
        ->and($transport->sent)->toBe([])
        ->and($store->opened)->toBe(0)
        ->and(payStripeAdapter($transport)->provider(payStripeConnection(ConnectionMode::Platform), $store))->toBeInstanceOf(NullPaymentProvider::class);
});

it('fails worded at first use, carrying no secret in its message, its trace or anything it wraps, for each way a checkout cannot open', function (Closure $script, bool $lends, bool $keyStored, string $reason) {
    $transport = $script(new FakeStripePaymentTransport(PAY_STRIPE_KEY));

    assert($transport instanceof FakeStripePaymentTransport);

    try {
        payStripeProvider($transport, payStripeStore($lends, $keyStored))->checkoutUrl(payStripeRequest());
        $failure = null;
    } catch (StripeFailed $thrown) {
        $failure = $thrown;
    }

    expect($failure)->toBeInstanceOf(StripeFailed::class)
        ->and($failure?->getMessage())->toContain($reason)
        ->and($failure?->getPrevious())->toBeNull()
        ->and($failure !== null && payStripeSecretsIn($failure))->toBeFalse();
})->with([
    'a refused key, Stripe echoing it' => [fn (FakeStripePaymentTransport $t) => $t->answer('POST', '/v1/checkout/sessions', 401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: '.PAY_STRIPE_KEY]]), true, true, 'Invalid API Key provided: [secret]'],
    'Stripe unreachable' => [fn (FakeStripePaymentTransport $t) => $t->unreachable('POST', '/v1/checkout/sessions'), true, true, 'Stripe could not be reached.'],
    'an answer that is no open session' => [fn (FakeStripePaymentTransport $t) => $t->answer('POST', '/v1/checkout/sessions', 200, ['object' => 'list']), true, true, 'not an open Checkout Session'],
    'no credentials lent' => [fn (FakeStripePaymentTransport $t) => $t, false, true, 'lends no credentials'],
    'no secret key stored' => [fn (FakeStripePaymentTransport $t) => $t, true, false, 'holds no secret key'],
]);

it('verifies a signed paid notification with the connection\'s signing secret and reads the booking and the payment, by the Checkout Session\'s id, from either paid event', function (string $type) {
    [$body, $signature] = payStripeEvent($type, payStripeSession(['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_3Q9z']));
    $notice = payStripeAdapter(new FakeStripePaymentTransport)->verify($body, $signature, payStripeCredentials());

    expect([$notice->eventId, $notice->type, $notice->paid, $notice->booking, $notice->paymentRef])->toBe(['evt_1Q2w3E', $type, true, payStripeBooking()->uuid, 'cs_test_a1B2c3']);
})->with(StripeAdapter::PAID_EVENTS);

it('reads the amount a paid session took from its amount_total in its currency\'s minor units, and reads none where Stripe sent none it can read, the notice paid all the same', function (array $over, ?array $amount) {
    [$body, $signature] = payStripeEvent('checkout.session.completed', payStripeSession(['status' => 'complete', 'payment_status' => 'paid', ...$over]));
    $notice = payStripeAdapter(new FakeStripePaymentTransport)->verify($body, $signature, payStripeCredentials());

    expect($notice->paid)->toBeTrue()
        ->and($notice->amount === null ? null : [$notice->amount->minor, $notice->amount->currency->code])->toBe($amount);
})->with([
    'euro' => [[], [4500, 'EUR']],
    'yen, which has no minor unit' => [['amount_total' => 5000, 'currency' => 'jpy'], [5000, 'JPY']],
    'nothing paid' => [['amount_total' => 0], [0, 'EUR']],
    'no amount' => [['amount_total' => null], null],
    'a negative amount' => [['amount_total' => -1], null],
    'no currency' => [['currency' => null], null],
    'a currency that is no code' => [['currency' => 'euro'], null],
]);

it('acknowledges every other event as a notice that confirms nothing: an unpaid completion, a failure, an expiry, a session that names no booking', function (string $type, array $over) {
    [$body, $signature] = payStripeEvent($type, payStripeSession($over));
    $notice = payStripeAdapter(new FakeStripePaymentTransport)->verify($body, $signature, payStripeCredentials());

    expect([$notice->type, $notice->paid, $notice->paymentRef, $notice->amount])->toBe([$type, false, null, null]);
})->with([
    'completed, a delayed method still unpaid' => ['checkout.session.completed', ['status' => 'complete']],
    'async payment failed' => ['checkout.session.async_payment_failed', ['status' => 'complete']],
    'expired' => ['checkout.session.expired', ['status' => 'expired']],
    'paid on a session no booking made' => ['checkout.session.completed', ['payment_status' => 'paid', 'metadata' => []]],
    'another object entirely' => ['payment_intent.succeeded', ['object' => 'payment_intent', 'id' => 'pi_3Q9z']],
]);

it('accepts any v1 signature that matches, as during a secret\'s rotation', function () {
    [$body, $signature] = payStripeEvent('checkout.session.expired', payStripeSession());

    expect(payStripeAdapter(new FakeStripePaymentTransport)->verify($body, 'v1=00ff,'.$signature, payStripeCredentials())->eventId)->toBe('evt_1Q2w3E');
});

it('refuses a wrong signature, a stale or future timestamp, a missing header, and a signed body that is not a Stripe event, naming no secret', function (Closure $notification) {
    $pair = $notification();

    assert(is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null));
    [$body, $signature] = $pair;

    try {
        payStripeAdapter(new FakeStripePaymentTransport)->verify($body, $signature, payStripeCredentials());
        $refusal = null;
    } catch (NotificationRefused $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(NotificationRefused::class)
        ->and($refusal !== null && payStripeSecretsIn($refusal))->toBeFalse();
})->with([
    'signed with another secret' => [fn () => payStripeEvent('checkout.session.completed', payStripeSession(['payment_status' => 'paid']), secret: 'whsec_another')],
    'signed six minutes ago' => [fn () => payStripeEvent('checkout.session.completed', payStripeSession(['payment_status' => 'paid']), signedAgo: 360)],
    'signed six minutes ahead' => [fn () => payStripeEvent('checkout.session.completed', payStripeSession(['payment_status' => 'paid']), signedAgo: -360)],
    'no header' => [fn () => [payStripeEvent('checkout.session.completed', payStripeSession())[0], '']],
    'a body edited after signing' => [fn () => [str_replace('paid', 'unpaid', payStripeEvent('checkout.session.completed', payStripeSession(['payment_status' => 'paid']))[0]), payStripeEvent('checkout.session.completed', payStripeSession(['payment_status' => 'paid']))[1]]],
    'signed, but not an event' => [function () {
        $at = payStripeNow()->getTimestamp();

        return ['{"hello":"world"}', sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.{"hello":"world"}', PAY_STRIPE_SIGNING))];
    }],
    'a paid event whose session has no id' => [fn () => payStripeEvent('checkout.session.completed', payStripeSession(['id' => '', 'payment_status' => 'paid']))],
]);

it('leaves the note on the PaymentIntent, reading a Checkout Session id back for its payment first and noting a PaymentIntent id directly', function (string $ref, array $paths) {
    $note = 'Booking 7f0c was cancelled. The slot is released and nothing has been refunded.';
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))
        ->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession(['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_3Q9z']))
        ->answer('POST', '/v1/payment_intents/pi_3Q9z', 200, ['id' => 'pi_3Q9z', 'object' => 'payment_intent', 'description' => $note, 'metadata' => ['note' => $note]]);
    payStripeProvider($transport)->flagPayment(payStripeBooking(), $ref, $note);

    expect(array_map(fn (array $sent): string => $sent['method'].' '.$sent['path'], $transport->sent))->toBe($paths)
        ->and(end($transport->sent)['form'] ?? [])->toBe(['description' => $note, 'metadata[note]' => $note])
        ->and(array_column($transport->sent, 'keyMatched'))->each->toBeTrue();
})->with([
    'by its session' => ['cs_test_a1B2c3', ['GET /v1/checkout/sessions/cs_test_a1B2c3', 'POST /v1/payment_intents/pi_3Q9z']],
    'by its payment' => ['pi_3Q9z', ['POST /v1/payment_intents/pi_3Q9z']],
]);

it('fails worded, naming no secret, where a session holds no payment or Stripe refuses the note', function (Closure $script, string $reason) {
    try {
        $transport = $script(new FakeStripePaymentTransport(PAY_STRIPE_KEY));
        assert($transport instanceof FakeStripePaymentTransport);
        payStripeProvider($transport)->flagPayment(payStripeBooking(), 'cs_test_a1B2c3', 'note');
        $failure = null;
    } catch (StripeFailed $thrown) {
        $failure = $thrown;
    }

    expect($failure?->getMessage())->toContain($reason)
        ->and($failure !== null && payStripeSecretsIn($failure))->toBeFalse();
})->with([
    'no payment yet' => [fn (FakeStripePaymentTransport $t) => $t->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession()), 'names no payment'],
    'refused' => [fn (FakeStripePaymentTransport $t) => $t->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession(['payment_intent' => 'pi_3Q9z']))->answer('POST', '/v1/payment_intents/pi_3Q9z', 403, ['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key \''.PAY_STRIPE_KEY.'\' does not have the required permissions.']]), 'The provided key \'[secret]\''],
]);

it('expires an open session, and answers a session Stripe already completed or expired as nothing to do', function (?string $status) {
    $transport = new FakeStripePaymentTransport(PAY_STRIPE_KEY);
    $status === null
        ? $transport->answer('POST', '/v1/checkout/sessions/cs_test_a1B2c3/expire', 200, payStripeSession(['status' => 'expired', 'url' => null]))
        : $transport->answer('POST', '/v1/checkout/sessions/cs_test_a1B2c3/expire', 400, ['error' => ['type' => 'invalid_request_error', 'message' => 'This Checkout Session is not in an expirable state.']])
            ->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession(['status' => $status, 'url' => null]));
    payStripeProvider($transport)->expireCheckout(payStripeBooking(), 'cs_test_a1B2c3');

    expect(array_column($transport->sent, 'method'))->toBe($status === null ? ['POST'] : ['POST', 'GET']);
})->with(['open' => [null], 'already completed' => ['complete'], 'already expired' => ['expired']]);

it('fails an expiry Stripe refuses for a session still open, worded and naming no secret', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))
        ->answer('POST', '/v1/checkout/sessions/cs_test_a1B2c3/expire', 401, ['error' => ['message' => 'Invalid API Key provided: '.PAY_STRIPE_KEY]])
        ->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 401, ['error' => ['message' => 'Invalid API Key provided: '.PAY_STRIPE_KEY]]);

    try {
        payStripeProvider($transport)->expireCheckout(payStripeBooking(), 'cs_test_a1B2c3');
        $failure = null;
    } catch (StripeFailed $thrown) {
        $failure = $thrown;
    }

    expect($failure?->getMessage())->toBe('Stripe did not expire a checkout: Invalid API Key provided: [secret]')
        ->and($failure !== null && payStripeSecretsIn($failure))->toBeFalse();
});

it('tests a connection with one authenticated read, answering passed, or failed with Stripe\'s reason and no secret', function () {
    $passing = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('GET', '/v1/checkout/sessions', 200, ['object' => 'list', 'data' => [], 'has_more' => false]);
    $failing = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('GET', '/v1/checkout/sessions', 401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: '.PAY_STRIPE_KEY]]);
    $passed = payStripeAdapter($passing)->check(payStripeCredentials());
    $failed = payStripeAdapter($failing)->check(payStripeCredentials());

    expect([$passed->ok, $passed->reason, $passed->key, $passed->values])->toBe([true, null, null, []])
        ->and($passing->sent[0]['form'] ?? null)->toBe(['limit' => 1])
        ->and($failed->ok)->toBeFalse()
        ->and($failed->reason)->toEndWith(': Invalid API Key provided: [secret]')
        ->and($failed->reason)->not->toContain(PAY_STRIPE_KEY);
});

/*
 * A failed test names the key of payments.php's reasons group its English reason is the line of,
 * and the values that fill it; PaymentsSectionTest holds each line filled to the English recorded,
 * since this suite boots no translator.
 */
it('names the reason key of each way the test fails and the values that fill it, a secret echoed by Stripe struck in the provider\'s own words', function (Closure $script, Credentials $credentials, string $key, array $values) {
    $transport = $script(new FakeStripePaymentTransport(PAY_STRIPE_KEY));

    assert($transport instanceof FakeStripePaymentTransport);

    $check = payStripeAdapter($transport)->check($credentials);

    expect([$check->ok, $check->key, $check->values])->toBe([false, $key, $values])
        ->and((string) json_encode([$check->reason, $check->values]))->not->toContain(PAY_STRIPE_KEY)->not->toContain(PAY_STRIPE_SIGNING);
})->with([
    'no secret key' => [fn (FakeStripePaymentTransport $t) => $t, new Credentials([]), StripeAdapter::REASON_NO_KEY, []],
    'unreachable' => [fn (FakeStripePaymentTransport $t) => $t->unreachable('GET', '/v1/checkout/sessions'), payStripeCredentials(), StripeAdapter::REASON_UNREACHABLE, []],
    'answered with no message of its own' => [fn (FakeStripePaymentTransport $t) => $t->answer('GET', '/v1/checkout/sessions', 503, []), payStripeCredentials(), StripeAdapter::REASON_STATUS, ['status' => '503']],
    'answered with its own message, echoing both secrets' => [
        fn (FakeStripePaymentTransport $t) => $t->answer('GET', '/v1/checkout/sessions', 401, ['error' => ['message' => 'No key '.PAY_STRIPE_KEY.' nor '.PAY_STRIPE_SIGNING.' here.']]),
        payStripeCredentials(), StripeAdapter::REASON_MESSAGE, ['reason' => 'No key [secret] nor [secret] here.'],
    ],
]);

/**
 * A connected Account as Stripe's Accounts API reference spells the object, trimmed to what the
 * adapter reads beside a few it does not; its requirements are the reference's own example.
 *
 * @param  array<array-key, mixed>  $over
 * @return array<array-key, mixed>
 */
function payStripeAccount(array $over = []): array
{
    return [
        'id' => 'acct_1Nv0FGQ9RKHgCVdK', 'object' => 'account', 'country' => 'GR', 'default_currency' => 'eur', 'charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false,
        'controller' => ['fees' => ['payer' => 'account'], 'is_controller' => true, 'losses' => ['payments' => 'stripe'], 'requirement_collection' => 'stripe', 'stripe_dashboard' => ['type' => 'full'], 'type' => 'application'],
        'requirements' => payStripeRequirements(),
        ...$over,
    ];
}

/**
 * @return array<string, mixed>
 */
function payStripeRequirements(): array
{
    return [
        'alternatives' => [], 'current_deadline' => null, 'currently_due' => ['business_profile.mcc', 'external_account', 'tos_acceptance.date'],
        'disabled_reason' => 'requirements.past_due', 'errors' => [], 'eventually_due' => ['business_profile.mcc', 'external_account', 'tos_acceptance.date'],
        'past_due' => ['external_account'], 'pending_verification' => [],
    ];
}

const PAY_STRIPE_RETURN = 'https://luna.test/admin/settings';

it('opens the connected account with the platform\'s secret key, sending the four controller properties as written, and reads the acct_ id back', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/accounts', 200, payStripeAccount());

    expect(payStripeAdapter($transport)->openAccount(payStripeCredentials()))->toBe('acct_1Nv0FGQ9RKHgCVdK')
        ->and($transport->sent)->toBe([['method' => 'POST', 'path' => '/v1/accounts', 'form' => [
            'controller[fees][payer]' => 'account',
            'controller[losses][payments]' => 'stripe',
            'controller[requirement_collection]' => 'stripe',
            'controller[stripe_dashboard][type]' => 'full',
        ], 'idempotencyKey' => null, 'keyMatched' => true]]);
});

it('asks for an account_onboarding link for the account, refreshing and returning to the address given, and reads its https url back', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/account_links', 200, [
        'object' => 'account_link', 'created' => payStripeNow()->getTimestamp(), 'expires_at' => payStripeNow(300)->getTimestamp(), 'url' => 'https://connect.stripe.com/setup/s/acct_1Nv0FGQ9RKHgCVdK/aBcDeFgH',
    ]);

    expect(payStripeAdapter($transport)->onboardingLink(payStripeCredentials(), 'acct_1Nv0FGQ9RKHgCVdK', PAY_STRIPE_RETURN))->toBe('https://connect.stripe.com/setup/s/acct_1Nv0FGQ9RKHgCVdK/aBcDeFgH')
        ->and($transport->sent)->toBe([['method' => 'POST', 'path' => '/v1/account_links', 'form' => [
            'account' => 'acct_1Nv0FGQ9RKHgCVdK',
            'refresh_url' => PAY_STRIPE_RETURN,
            'return_url' => PAY_STRIPE_RETURN,
            'type' => 'account_onboarding',
        ], 'idempotencyKey' => null, 'keyMatched' => true]]);
});

it('fails worded at each platform call, carrying neither platform key in its message, its trace or anything it wraps', function (Closure $call, Closure $script, string $message) {
    $transport = $script(new FakeStripePaymentTransport(PAY_STRIPE_KEY));

    assert($transport instanceof FakeStripePaymentTransport);

    try {
        $call(payStripeAdapter($transport));
        $failure = null;
    } catch (StripeFailed $thrown) {
        $failure = $thrown;
    }

    expect($failure)->toBeInstanceOf(StripeFailed::class)
        ->and($failure?->getMessage())->toBe($message)
        ->and($failure?->getPrevious())->toBeNull()
        ->and($failure !== null && payStripeSecretsIn($failure))->toBeFalse();
})->with([
    'the account, a refused key Stripe echoes' => [
        fn (StripeAdapter $adapter) => $adapter->openAccount(payStripeCredentials()),
        fn (FakeStripePaymentTransport $t) => $t->answer('POST', '/v1/accounts', 401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: '.PAY_STRIPE_KEY]]),
        'Stripe did not open the connected account: Invalid API Key provided: [secret]',
    ],
    'the account, Stripe unreachable' => [
        fn (StripeAdapter $adapter) => $adapter->openAccount(payStripeCredentials()),
        fn (FakeStripePaymentTransport $t) => $t->unreachable('POST', '/v1/accounts'),
        'Stripe did not open the connected account: '.StripeUnreachable::make()->getMessage(),
    ],
    'the account, an answer naming no account' => [
        fn (StripeAdapter $adapter) => $adapter->openAccount(payStripeCredentials()),
        fn (FakeStripePaymentTransport $t) => $t->answer('POST', '/v1/accounts', 200, payStripeAccount(['id' => 'cus_N0t4nAcc0unt'])),
        'Stripe did not open the connected account: the answer names no account.',
    ],
    'the link, a refusal echoing both keys' => [
        fn (StripeAdapter $adapter) => $adapter->onboardingLink(payStripeCredentials(), 'acct_1Nv0FGQ9RKHgCVdK', PAY_STRIPE_RETURN),
        fn (FakeStripePaymentTransport $t) => $t->answer('POST', '/v1/account_links', 400, ['error' => ['type' => 'invalid_request_error', 'message' => 'No such account under '.PAY_STRIPE_KEY.' or '.PAY_STRIPE_SIGNING]]),
        'Stripe did not open the onboarding link: No such account under [secret] or [secret]',
    ],
    'the link, Stripe unreachable' => [
        fn (StripeAdapter $adapter) => $adapter->onboardingLink(payStripeCredentials(), 'acct_1Nv0FGQ9RKHgCVdK', PAY_STRIPE_RETURN),
        fn (FakeStripePaymentTransport $t) => $t->unreachable('POST', '/v1/account_links'),
        'Stripe did not open the onboarding link: '.StripeUnreachable::make()->getMessage(),
    ],
    'the link, an answer carrying no https link' => [
        fn (StripeAdapter $adapter) => $adapter->onboardingLink(payStripeCredentials(), 'acct_1Nv0FGQ9RKHgCVdK', PAY_STRIPE_RETURN),
        fn (FakeStripePaymentTransport $t) => $t->answer('POST', '/v1/account_links', 200, ['object' => 'account_link', 'url' => 'http://connect.stripe.com/setup/s/acct_1Nv0FGQ9RKHgCVdK/aBcDeFgH']),
        'Stripe did not open the onboarding link: the answer carries no link.',
    ],
]);

it('reads account.updated to the account\'s standing, the requirements as Stripe sent them: charges enabled is taking payments, kept while Stripe still lists something due, details unsubmitted is unfinished, anything else is wanting', function (array $over, ConnectionState $state, ?array $kept) {
    [$body, $signature] = payStripeEvent('account.updated', payStripeAccount($over), account: 'acct_1Nv0FGQ9RKHgCVdK');
    $notice = payStripeAdapter(new FakeStripePaymentTransport)->verifyPlatform($body, $signature, payStripeCredentials());

    expect([$notice->eventId, $notice->type, $notice->accountRef, $notice->standing?->state, $notice->standing?->requirements])
        ->toBe(['evt_1Q2w3E', 'account.updated', 'acct_1Nv0FGQ9RKHgCVdK', $state, $kept]);
})->with([
    'charges enabled, something currently due and past due' => [['charges_enabled' => true, 'details_submitted' => true], ConnectionState::Active, payStripeRequirements()],
    'charges enabled before the details are in, something due' => [['charges_enabled' => true], ConnectionState::Active, payStripeRequirements()],
    'charges enabled, something currently due alone' => [
        ['charges_enabled' => true, 'details_submitted' => true, 'requirements' => [...payStripeRequirements(), 'past_due' => [], 'disabled_reason' => null]],
        ConnectionState::Active,
        [...payStripeRequirements(), 'past_due' => [], 'disabled_reason' => null],
    ],
    'charges enabled, something past due alone' => [
        ['charges_enabled' => true, 'details_submitted' => true, 'requirements' => [...payStripeRequirements(), 'currently_due' => []]],
        ConnectionState::Active,
        [...payStripeRequirements(), 'currently_due' => []],
    ],
    'charges enabled, nothing currently or past due, something due eventually' => [
        ['charges_enabled' => true, 'details_submitted' => true, 'requirements' => [...payStripeRequirements(), 'currently_due' => [], 'past_due' => [], 'disabled_reason' => null]],
        ConnectionState::Active,
        null,
    ],
    'charges enabled, no requirements object' => [['charges_enabled' => true, 'details_submitted' => true, 'requirements' => null], ConnectionState::Active, null],
    'details not submitted' => [[], ConnectionState::Pending, payStripeRequirements()],
    'details submitted, charges not enabled' => [['details_submitted' => true], ConnectionState::Restricted, payStripeRequirements()],
]);

it('names the account from data.object.id, and from the event\'s own account where the object names none', function (array $over, ?string $eventAccount, ?string $expected) {
    [$body, $signature] = payStripeEvent('account.updated', payStripeAccount($over), account: $eventAccount);

    expect(payStripeAdapter(new FakeStripePaymentTransport)->verifyPlatform($body, $signature, payStripeCredentials())->accountRef)->toBe($expected);
})->with([
    'both, the object wins' => [[], 'acct_0therEventAcct', 'acct_1Nv0FGQ9RKHgCVdK'],
    'the object alone' => [[], null, 'acct_1Nv0FGQ9RKHgCVdK'],
    'the event alone' => [['id' => null], 'acct_0therEventAcct', 'acct_0therEventAcct'],
    'neither' => [['id' => null], null, null],
]);

it('answers any other platform event as a notice with no standing, naming the event\'s account', function (string $type, array $object) {
    [$body, $signature] = payStripeEvent($type, $object, account: 'acct_1Nv0FGQ9RKHgCVdK');
    $notice = payStripeAdapter(new FakeStripePaymentTransport)->verifyPlatform($body, $signature, payStripeCredentials());

    expect([$notice->type, $notice->accountRef, $notice->standing])->toBe([$type, 'acct_1Nv0FGQ9RKHgCVdK', null]);
})->with([
    'a capability moved' => ['capability.updated', ['id' => 'card_payments', 'object' => 'capability', 'account' => 'acct_1Nv0FGQ9RKHgCVdK', 'status' => 'active']],
    'a payout' => ['payout.paid', ['id' => 'po_1Q2w', 'object' => 'payout', 'amount' => 4500]],
    'account.updated carrying no account object' => ['account.updated', ['id' => 'po_1Q2w', 'object' => 'payout']],
]);

it('refuses a platform notification with a wrong, stale or missing signature, or a signed body that is not an event, naming neither platform key', function (Closure $notification) {
    $pair = $notification();

    assert(is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null));
    [$body, $signature] = $pair;

    try {
        payStripeAdapter(new FakeStripePaymentTransport)->verifyPlatform($body, $signature, payStripeCredentials());
        $refusal = null;
    } catch (NotificationRefused $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->toBeInstanceOf(NotificationRefused::class)
        ->and($refusal !== null && payStripeSecretsIn($refusal))->toBeFalse();
})->with([
    'signed with another secret' => [fn () => payStripeEvent('account.updated', payStripeAccount(), secret: 'whsec_another', account: 'acct_1Nv0FGQ9RKHgCVdK')],
    'signed six minutes ago' => [fn () => payStripeEvent('account.updated', payStripeAccount(), signedAgo: 360, account: 'acct_1Nv0FGQ9RKHgCVdK')],
    'no header' => [fn () => [payStripeEvent('account.updated', payStripeAccount())[0], '']],
    'a header with no v1' => [fn () => [payStripeEvent('account.updated', payStripeAccount())[0], 't='.payStripeNow()->getTimestamp()]],
    'signed, but not an event' => [function () {
        $at = payStripeNow()->getTimestamp();
        $body = (string) json_encode(payStripeAccount());

        return [$body, sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.'.$body, PAY_STRIPE_SIGNING))];
    }],
    'signed, an event whose id is not one' => [function () {
        $at = payStripeNow()->getTimestamp();
        $body = (string) json_encode(['id' => 'acct_1Nv0FGQ9RKHgCVdK', 'object' => 'event', 'type' => 'account.updated', 'data' => ['object' => payStripeAccount()]]);

        return [$body, sprintf('t=%d,v1=%s', $at, hash_hmac('sha256', $at.'.'.$body, PAY_STRIPE_SIGNING))];
    }],
]);

/*
 * Platform mode's payments: the platform's key, the tenant's connected account named on every
 * request as Stripe-Account, a direct charge, and the fee attached as the session's
 * application_fee_amount, computed from the total when the session is created. The customer is
 * asked for the total and nothing more.
 */

const PAY_STRIPE_PLATFORM_KEY = 'sk_test_51PlatformKeyNeverShown';
const PAY_STRIPE_PLATFORM_SIGNING = 'whsec_PlatformSigningNeverShown';
const PAY_STRIPE_ACCOUNT = 'acct_1Nv0FGQ9RKHgCVdK';

function payStripePlatform(mixed $fee = ['percent' => '1.5', 'fixed' => 'EUR:25,JPY:30']): FixedPlatform
{
    return new FixedPlatform(['stripe' => [StripeAdapter::SECRET_KEY => PAY_STRIPE_PLATFORM_KEY, StripeAdapter::WEBHOOK_SECRET => PAY_STRIPE_PLATFORM_SIGNING]], $fee);
}

function payStripePlatformProvider(FakeStripePaymentTransport $transport, FixedPlatform $platform, ?PaymentConnections $store = null, string $account = PAY_STRIPE_ACCOUNT): PaymentProvider
{
    $connection = new PaymentConnection('stripe', ConnectionMode::Platform, ConnectionState::Active, $account, null, null, true, false);

    return payStripeAdapter($transport, $platform)->provider($connection, $store ?? payStripeStore());
}

it('opens a platform connection\'s session on the connected account with the platform\'s key, asking the customer for the total and attaching the fee computed from it', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_PLATFORM_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    $store = payStripeStore();
    $request = payStripeRequest(total: new Money(4500, Currency::of('EUR')));
    $session = payStripePlatformProvider($transport, payStripePlatform(), $store)->checkoutUrl($request);
    $sent = $transport->sent[0] ?? null;

    assert($session instanceof CheckoutSession && $sent !== null);

    expect([$sent['method'], $sent['path'], $sent['keyMatched'], $sent['account'] ?? null])->toBe(['POST', '/v1/checkout/sessions', true, PAY_STRIPE_ACCOUNT])
        ->and($sent['form'])->toMatchArray([
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => 'eur',
            'line_items[0][price_data][unit_amount]' => 4500,
            'payment_intent_data[application_fee_amount]' => 93,
            'metadata[booking]' => $request->booking->uuid,
        ])
        ->and($store->opened)->toBe(0)
        ->and($session->ref)->toBe('cs_test_a1B2c3');
});

it('keys a platform session by the account and the fee as well, and own mode by its five inputs alone while no move has cleared a session', function () {
    $own = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    $platform = (new FakeStripePaymentTransport(PAY_STRIPE_PLATFORM_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    $terms = payStripePlatform();
    payStripeProvider($own)->checkoutUrl(payStripeRequest());
    payStripePlatformProvider($platform, $terms)->checkoutUrl(payStripeRequest());
    payStripePlatformProvider($platform, $terms->charging(['percent' => '2']))->checkoutUrl(payStripeRequest());
    // The same booking under the same fee on another connected account: the account alone moves the
    // key.
    payStripePlatformProvider($platform, $terms, account: 'acct_1Other0Account0Two')->checkoutUrl(payStripeRequest());
    $booking = payStripeRequest()->booking;
    $five = [$booking->uuid, payStripeRequest()->endsBy->getTimestamp(), $booking->total->minor, $booking->total->currency->code, payStripeRequest()->endsBy->getTimestamp() + StripePaymentProvider::FLOOR];
    $fee = PlatformFee::of(['percent' => '2'])?->on($booking->total)->minor;

    expect($own->sent[0]['idempotencyKey'] ?? null)->toBe('checkout-'.hash('sha256', implode('|', $five)))
        ->and(array_column($platform->sent, 'idempotencyKey'))->each->toStartWith('checkout-')
        ->and(array_column($platform->sent, 'account'))->toBe([PAY_STRIPE_ACCOUNT, PAY_STRIPE_ACCOUNT, 'acct_1Other0Account0Two'])
        ->and($platform->sent[1]['idempotencyKey'] ?? null)->toBe('checkout-'.hash('sha256', implode('|', [...$five, PAY_STRIPE_ACCOUNT, $fee])))
        ->and($platform->sent[2]['idempotencyKey'] ?? null)->toBe('checkout-'.hash('sha256', implode('|', [...$five, 'acct_1Other0Account0Two', $fee])))
        ->and(array_unique(array_column([...$own->sent, ...$platform->sent], 'idempotencyKey')))->toHaveCount(4);
});

it('never asks a fee above the total, a currency with no minor unit included, and sends no fee where it comes to nothing', function (Money $total, mixed $fee, ?int $sent) {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_PLATFORM_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    payStripePlatformProvider($transport, payStripePlatform($fee))->checkoutUrl(payStripeRequest(total: $total));
    $form = $transport->sent[0]['form'] ?? [];

    expect($form['payment_intent_data[application_fee_amount]'] ?? null)->toBe($sent)
        ->and($form['line_items[0][price_data][unit_amount]'] ?? null)->toBe($total->minor);
})->with([
    'yen, a percentage and a fixed part' => [new Money(1000, Currency::of('JPY')), ['percent' => '2.25', 'fixed' => 'JPY:30'], 53],
    'yen, the fixed part above the total' => [new Money(20, Currency::of('JPY')), ['fixed' => 'JPY:30'], 20],
    'euro, all of it' => [new Money(4500, Currency::of('EUR')), ['percent' => '100', 'fixed' => 'EUR:25'], 4500],
    'a fee of zero' => [new Money(4500, Currency::of('EUR')), ['percent' => '0'], null],
    'a fixed part in another currency alone' => [new Money(4500, Currency::of('GBP')), ['fixed' => 'EUR:25'], null],
    'the fee unset after the connection was made' => [new Money(4500, Currency::of('EUR')), null, null],
]);

it('still opens a platform connection\'s session where the fee is unset after the connection was made, with no fee, and says so on one error line per session opened; own mode, a fee configured and a session read back say nothing', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_PLATFORM_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())
        ->answer('POST', '/v1/checkout/sessions', 200, payStripeSession())->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession());
    $own = (new FakeStripePaymentTransport(PAY_STRIPE_KEY))->answer('POST', '/v1/checkout/sessions', 200, payStripeSession());
    $unset = new RecordingPaymentLog;
    $quiet = new RecordingPaymentLog;
    $connection = new PaymentConnection('stripe', ConnectionMode::Platform, ConnectionState::Active, PAY_STRIPE_ACCOUNT, null, null, true, false);
    $noFee = payStripeAdapter($transport, payStripePlatform(null), $unset)->provider($connection, payStripeStore());

    $session = $noFee->checkoutUrl(payStripeRequest());
    $noFee->checkoutUrl(payStripeRequest(holdLeft: 600));
    $noFee->checkoutUrl(payStripeRequest('cs_test_a1B2c3'));
    payStripeAdapter($transport, payStripePlatform(), $quiet)->provider($connection, payStripeStore())->checkoutUrl(payStripeRequest());
    payStripeAdapter($own, null, $quiet)->provider(payStripeConnection(), payStripeStore())->checkoutUrl(payStripeRequest());

    assert($session instanceof CheckoutSession);

    expect($session->ref)->toBe('cs_test_a1B2c3')
        ->and(array_column($transport->sent, 'form')[0] ?? [])->not->toHaveKey('payment_intent_data[application_fee_amount]')
        ->and($unset->feeUnset)->toBe(2)
        ->and($quiet->feeUnset)->toBe(0)
        ->and([...$unset->threw, ...$quiet->threw])->toBe([]);
});

it('reads a platform session back on the connected account and creates nothing, so a fee changed since leaves it as it was', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_PLATFORM_KEY))->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession());
    $session = payStripePlatformProvider($transport, payStripePlatform(['percent' => '9']))->checkoutUrl(payStripeRequest('cs_test_a1B2c3'));

    assert($session instanceof CheckoutSession);

    expect($session->ref)->toBe('cs_test_a1B2c3')
        ->and(array_map(fn (array $sent): array => [$sent['method'], $sent['path'], $sent['account'] ?? null, $sent['form']], $transport->sent))
        ->toBe([['GET', '/v1/checkout/sessions/cs_test_a1B2c3', PAY_STRIPE_ACCOUNT, []]]);
});

it('notes and expires a platform connection\'s payment on the connected account', function () {
    $transport = (new FakeStripePaymentTransport(PAY_STRIPE_PLATFORM_KEY))
        ->answer('GET', '/v1/checkout/sessions/cs_test_a1B2c3', 200, payStripeSession(['payment_intent' => 'pi_3Mtw', 'status' => 'complete']))
        ->answer('POST', '/v1/payment_intents/pi_3Mtw', 200, ['id' => 'pi_3Mtw', 'object' => 'payment_intent'])
        ->answer('POST', '/v1/checkout/sessions/cs_test_a1B2c3/expire', 200, payStripeSession(['status' => 'expired']));
    $provider = payStripePlatformProvider($transport, payStripePlatform());
    $provider->flagPayment(payStripeBooking(), 'cs_test_a1B2c3', 'The note.');
    $provider->expireCheckout(payStripeBooking(), 'cs_test_a1B2c3');

    expect(array_map(fn (array $sent): array => [$sent['method'], $sent['path'], $sent['keyMatched'], $sent['account'] ?? null], $transport->sent))->toBe([
        ['GET', '/v1/checkout/sessions/cs_test_a1B2c3', true, PAY_STRIPE_ACCOUNT],
        ['POST', '/v1/payment_intents/pi_3Mtw', true, PAY_STRIPE_ACCOUNT],
        ['POST', '/v1/checkout/sessions/cs_test_a1B2c3/expire', true, PAY_STRIPE_ACCOUNT],
    ]);
});

it('answers the null provider for a platform connection whose platform keys are unset or which names no connected account', function () {
    $transport = new FakeStripePaymentTransport;
    $store = payStripeStore();
    $unset = new PaymentConnection('stripe', ConnectionMode::Platform, ConnectionState::Active, PAY_STRIPE_ACCOUNT, null, null, true, false);
    $noAccount = new PaymentConnection('stripe', ConnectionMode::Platform, ConnectionState::Active, null, null, null, true, false);

    expect(payStripeAdapter($transport, new FixedPlatform([], ['percent' => '1.5']))->provider($unset, $store))->toBeInstanceOf(NullPaymentProvider::class)
        ->and(payStripeAdapter($transport, payStripePlatform())->provider($noAccount, $store))->toBeInstanceOf(NullPaymentProvider::class)
        ->and($transport->sent)->toBe([])
        ->and($store->opened)->toBe(0);
});

it('reads a paid event on the platform endpoint as the connected account\'s payment notice, the booking from the session and the payment by its id', function () {
    [$body, $signature] = payStripeEvent('checkout.session.completed', payStripeSession(['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_3Mtw']), secret: PAY_STRIPE_PLATFORM_SIGNING, account: PAY_STRIPE_ACCOUNT);
    $notice = payStripeAdapter(new FakeStripePaymentTransport)->verifyPlatform($body, $signature, new Credentials([StripeAdapter::SECRET_KEY => PAY_STRIPE_PLATFORM_KEY, StripeAdapter::WEBHOOK_SECRET => PAY_STRIPE_PLATFORM_SIGNING]));

    expect([$notice->eventId, $notice->type, $notice->accountRef, $notice->standing])->toBe(['evt_1Q2w3E', 'checkout.session.completed', PAY_STRIPE_ACCOUNT, null])
        ->and([$notice->payment?->paid, $notice->payment?->booking, $notice->payment?->paymentRef])->toBe([true, payStripeBooking()->uuid, 'cs_test_a1B2c3']);
});
