<?php

use App\Application\Checkpoint;
use App\Application\GuardedWrite;
use App\Application\Notification\MessageKey;
use App\Http\ErrorEnvelope;
use App\Http\Middleware\RequiresPaymentSignature;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\ExpireHoldsFactory;
use App\Infrastructure\Payment\NotificationUnhandled;
use App\Infrastructure\Payment\PaymentWebhook;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixtureDay;
use Tests\Support\SeamLog;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The payment notification: POST /webhooks/paydesk/{token} on the central host, own mode,
 * confirming luna's online Colour booking from the notification alone. The fake is connected
 * and active with the token hook-token-luna; it seals a body with the HMAC of the connection's
 * signing phrase.
 *
 * Not wrapped in a transaction: the hold and the paid transition enter the transaction runner,
 * which refuses to open inside another, and the fake records whether a transaction was open
 * when it verified, which only means something here. The pinned clock is the one the hold's
 * start and the manage token's lifetime read; liveness is the database's now().
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
    seamTransport()->flush();
    settingsOf('luna')->update(['reminder_lead_hours' => 0]);
    connectPaymentFake();
    $this->log = new SeamLog;
    app()->instance(LoggerInterface::class, $this->log);
});

afterEach(function () {
    Checkpoint::observe(null);
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A Colour hold for Casey, and the pay hop visited once, so the session pd_1 is recorded and the
 * token sealed onto the row.
 *
 * @return array{uuid: string, manage_token: string}
 */
function paidHold(TestCase $test, string $service = 'Colour', bool $hop = true): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $ids('services', $service), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable(FixtureDay::START, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    if ($hop) {
        $test->get(tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $held['manage_token']])))->assertStatus(303);
    }

    return ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token']];
}

/**
 * The links a confirmation after a payment carries where no token was seen, in order: Book again
 * for paidHold's Colour on Elena and the closing line to My bookings, each under the prefix of the
 * booking's language, English here. Neither is a credential.
 *
 * @return list<string>
 */
function paidLinksWithoutAToken(): array
{
    $urls = app(TenantUrls::class);

    return [
        $urls->bookingLink(Slug::of('luna'), ['service' => seamId('services', 'Colour'), 'staff' => seamId('staff', 'Elena Papadaki')], SiteLanguage::English),
        $urls->myBookings(Slug::of('luna'), SiteLanguage::English),
    ];
}

/**
 * A Paydesk notification body: a settled payment for a booking unless a test says otherwise,
 * carrying the amount paid where one is given, in minor units beside its currency.
 *
 * @param  array{minor: int, currency: string}|null  $amount
 */
function paydeskBody(string $event, ?string $booking, string $type = FakePaymentAdapter::SETTLED, string $payment = 'pd_1', ?array $amount = null): string
{
    return json_encode(['id' => $event, 'type' => $type, 'booking' => $booking, 'payment' => $payment, ...($amount === null ? [] : ['amount' => $amount])], JSON_THROW_ON_ERROR);
}

/**
 * The notification posted as Paydesk posts it, sealed with luna's signing phrase unless a test
 * says otherwise.
 *
 * @return TestResponse<Response>
 */
function deliverPaydesk(TestCase $test, string $body, ?string $seal = null, string $token = 'hook-token-luna', string $provider = FakePaymentAdapter::SLUG): TestResponse
{
    return $test->call('POST', centralUrl('/webhooks/'.$provider.'/'.$token), [], [], [], [
        'HTTP_PAYDESK_SEAL' => $seal ?? FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * @return array<mixed>
 */
function paidRow(string $uuid): array
{
    return (array) DB::table('tenant_luna.bookings')->where('uuid', $uuid)->first();
}

/**
 * @return array<int, array<mixed>>
 */
function paymentEventRows(): array
{
    return array_map(fn ($row): array => (array) $row, DB::table('payment_events')->orderBy('id')->get(['provider', 'route', 'tenant_id', 'event_id', 'type', 'refusal', 'processed_at'])->all());
}

function paidAudits(string $uuid): int
{
    $id = paidRow($uuid)['id'];

    return DB::table('tenant_luna.audit_log')->where('booking_id', $id)->where('action', 'confirmed')->count();
}

function paidTenantId(): int
{
    return Tenant::query()->where('slug', 'luna')->sole()->id;
}

it('confirms a held online booking from the notification alone: no confirm request, no return, the hold columns null', function () {
    $held = paidHold($this);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk()->assertContent('');

    $row = paidRow($held['uuid']);

    expect([$row['status'], $row['hold_class'], $row['hold_expires_at'], $row['payment_ref']])->toBe(['confirmed', null, null, 'pd_1'])
        ->and(paidAudits($held['uuid']))->toBe(1)
        ->and(DB::table('tenant_luna.audit_log')->where('booking_id', $row['id'])->where('action', 'confirmed')->value('actor'))->toBe('job:payment')
        ->and(paymentEventRows())->toHaveCount(1)
        ->and(paymentEventRows()[0]['processed_at'])->not->toBeNull()
        ->and([paymentEventRows()[0]['provider'], paymentEventRows()[0]['route'], paymentEventRows()[0]['event_id'], paymentEventRows()[0]['type'], paymentEventRows()[0]['refusal']])
        ->toBe([FakePaymentAdapter::SLUG, 'own', 'evt_1', FakePaymentAdapter::SETTLED, null])
        ->and(seamAt('casey@example.com')->getSubject())->toBe(customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and(paymentFake()->verified)->toBe([['transactionOpen' => false]]);
});

it('records the amount a paid notice carried on the paid transition\'s trail row, beside payment_named, in minor units and its currency, and compares nothing: an amount other than the total still confirms, and a notice carrying none records none', function (?int $minor, string $currency, array $facts) {
    $held = paidHold($this);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid'], amount: $minor === null ? null : ['minor' => $minor, 'currency' => $currency]))->assertOk();

    $row = paidRow($held['uuid']);
    $payload = DB::table('tenant_luna.audit_log')->where('booking_id', $row['id'])->where('action', 'confirmed')->value('payload');
    $payload = is_string($payload) ? json_decode($payload, true) : null;
    $expected = ['status_from' => 'pending', 'status_to' => 'confirmed', 'hold_class' => 'checkout', 'payment_named' => true, ...$facts];
    is_array($payload) && ksort($payload);
    ksort($expected);

    expect($row['status'])->toBe('confirmed')
        ->and($payload)->toBe($expected);
})->with([
    'the Colour\'s total, EUR 80.00' => [8000, 'EUR', ['paid_minor' => 8000, 'paid_currency' => 'EUR']],
    'an amount that is not the total, confirmed all the same' => [1200, 'EUR', ['paid_minor' => 1200, 'paid_currency' => 'EUR']],
    'no amount at all' => [null, 'EUR', []],
]);

it('confirms once for the same notification delivered twice: one confirmation, one audit row, the second answered 200 and handling nothing', function () {
    $held = paidHold($this);
    $body = paydeskBody('evt_1', $held['uuid']);

    deliverPaydesk($this, $body)->assertOk();
    $sent = count(seamSent());
    deliverPaydesk($this, $body)->assertOk()->assertContent('');

    expect(count(seamSent()))->toBe($sent)
        ->and(seamAt('casey@example.com'))->not->toBeNull()
        ->and(paidAudits($held['uuid']))->toBe(1)
        ->and(paymentEventRows())->toHaveCount(1);
});

it('treats a second event for a booking already confirmed as the no-op it is, told apart by the re-read', function () {
    $held = paidHold($this);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();
    deliverPaydesk($this, paydeskBody('evt_2', $held['uuid']))->assertOk();

    expect(paidAudits($held['uuid']))->toBe(1)
        ->and(seamAt('casey@example.com'))->not->toBeNull()
        ->and(array_column(paymentEventRows(), 'event_id'))->toBe(['evt_1', 'evt_2'])
        ->and(array_filter(array_column(paymentEventRows(), 'processed_at')))->toHaveCount(2);
});

it('refuses a bad signature with the envelope, records it refused with none of its body, and processes nothing', function (string $seal) {
    $held = paidHold($this);
    $body = paydeskBody('evt_secret_body', $held['uuid']);
    $bound = [];
    DB::listen(function (QueryExecuted $query) use (&$bound): void {
        $bound[] = $query->sql.' '.json_encode($query->bindings);
    });

    deliverPaydesk($this, $body, $seal)
        ->assertStatus(400)
        ->assertExactJson(['code' => 'invalid_signature', 'message' => 'invalid_signature', 'data' => ['status' => 400, 'detail' => RequiresPaymentSignature::REFUSED_DETAIL]]);

    expect(paymentEventRows())->toBe([['provider' => FakePaymentAdapter::SLUG, 'route' => 'own', 'tenant_id' => paidTenantId(), 'event_id' => null, 'type' => null, 'refusal' => 'signature', 'processed_at' => null]])
        ->and(paidRow($held['uuid'])['status'])->toBe('pending')
        ->and(seamSent())->toBe([])
        ->and(implode("\n", $bound))->not->toContain('evt_secret_body')
        ->and(json_encode($this->log->records))->not->toContain('evt_secret_body')
        ->and(json_encode($this->log->records))->not->toContain(paymentFakeCredentials()['signing_phrase'])
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => PaymentWebhook::REFUSED,
            'context' => ['provider' => FakePaymentAdapter::SLUG, 'route' => 'own', 'tenant' => paidTenantId(), 'refusal' => 'signature'],
        ]]);
})->with([
    'a wrong seal' => [str_repeat('0', 64)],
    'no seal at all' => [''],
    'sealed with another phrase' => [FakePaymentAdapter::seal('{}', 'another phrase')],
]);

it('refuses a body that verifies but is not a notification as it refuses a bad signature', function () {
    $body = 'not json at all';

    deliverPaydesk($this, $body)->assertStatus(400)->assertJsonPath('code', 'invalid_signature');

    expect(array_column(paymentEventRows(), 'refusal'))->toBe(['signature']);
});

it('answers an unknown token and an unknown provider with the envelope\'s 404, recorded as refused, and processes nothing', function (string $provider, string $token, string $refusal) {
    $held = paidHold($this);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']), null, $token, $provider)
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('data.status', 404);

    expect(paymentEventRows())->toBe([['provider' => $provider, 'route' => 'own', 'tenant_id' => null, 'event_id' => null, 'type' => null, 'refusal' => $refusal, 'processed_at' => null]])
        ->and(paidRow($held['uuid'])['status'])->toBe('pending')
        ->and(paymentFake()->verified)->toBe([]);
})->with([
    'a token nobody holds' => [FakePaymentAdapter::SLUG, 'hook-token-nobody', 'unknown_route'],
    'luna\'s token under another provider' => ['stripe', 'hook-token-luna', 'unknown_route'],
    'a provider this build does not register' => ['paypal', 'hook-token-luna', 'unknown_provider'],
]);

it('logs, marks and confirms nothing for a booking we do not have and for one that names none', function (?string $booking) {
    deliverPaydesk($this, paydeskBody('evt_1', $booking))->assertOk();

    expect(paymentEventRows()[0]['processed_at'])->not->toBeNull()
        ->and(seamSent())->toBe([])
        ->and($this->log->records)->toBe([[
            'level' => 'warning',
            'message' => PaymentWebhook::IGNORED,
            'context' => ['tenant' => 'luna', 'event' => 'evt_1', 'booking' => $booking, 'payment' => 'pd_1', 'outcome' => 'Unknown'],
        ]]);
})->with([
    'an unknown uuid' => ['0b8e7c6a-1d2f-4e3a-9b5c-7d6e5f4a3b2c'],
    'not a uuid' => ['nothing-like-one'],
    'no booking named' => [null],
]);

it('logs as late, marks and confirms nothing for a booking no longer held, whichever road ended its hold', function (string $road) {
    $held = paidHold($this);

    match ($road) {
        'released' => $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['manage_token']))->assertSuccessful(),
        'cancelled' => $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/cancel'), ['token' => $held['manage_token']])->assertSuccessful(),
        'lapsed, not yet swept' => DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]),
        default => null,
    };
    $status = paidRow($held['uuid'])['status'];

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();

    expect(paidRow($held['uuid'])['status'])->toBe($status)
        ->and(paidAudits($held['uuid']))->toBe(0)
        ->and(paymentEventRows()[0]['processed_at'])->not->toBeNull()
        ->and(array_filter(seamSent(), fn ($message): bool => str_contains((string) $message->getSubject(), 'confirmed')))->toBe([])
        ->and(end($this->log->records))->toBe([
            'level' => 'warning',
            'message' => PaymentWebhook::LATE,
            'context' => ['tenant' => 'luna', 'event' => 'evt_1', 'booking' => $held['uuid'], 'payment' => 'pd_1', 'outcome' => 'NotHeld'],
        ]);
})->with(['released', 'cancelled', 'lapsed, not yet swept']);

/**
 * A held Colour booking whose hold lapsed a second ago and has not been swept, so a payment
 * notice for it is late.
 *
 * @return array{uuid: string, manage_token: string}
 */
function lateHold(TestCase $test): array
{
    $held = paidHold($test);
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
    seamTransport()->flush();

    return $held;
}

/**
 * @return list<array<mixed>>
 */
function lateFlagsAsked(): array
{
    return array_values(array_filter(paymentFake()->asked, fn (array $question): bool => $question[0] === 'flagPayment'));
}

it('tells the owners alone, once per event, that a late payment bought nothing and that refunding it is theirs to do in their provider\'s dashboard, naming the booking and carrying no secret, whether or not the notice named the payment', function (?string $payment) {
    addTenantUser('manager@luna.example', 'manager');
    addTenantUser('stylist@luna.example', 'staff', 'Elena Papadaki');
    $held = lateHold($this);
    $body = json_encode(['id' => 'evt_late', 'type' => FakePaymentAdapter::SETTLED, 'booking' => $held['uuid'], 'payment' => $payment], JSON_THROW_ON_ERROR);

    deliverPaydesk($this, $body)->assertOk();
    deliverPaydesk($this, $body)->assertOk();

    $sent = seamSent();
    $mail = $sent[0] ?? null;
    assert($mail !== null);
    $text = (string) $mail->getTextBody();
    $secrets = [...array_values(paymentFakeCredentials()), $held['manage_token']];
    $tenant = Tenant::query()->where('slug', 'luna')->sole();

    expect($sent)->toHaveCount(1)
        ->and(array_map(fn ($to): string => $to->getAddress(), $mail->getTo()))->toBe(['owner@luna.example'])
        ->and($mail->getSubject())->toBe(businessMailLine('business-mail.late_payment.subject', ['business' => $tenant->name]))
        ->and($text)->toContain(businessMailLine('business-mail.late_payment.reference').': '.$held['uuid'])
        // That it bought nothing, confirmed nothing, refunded nothing and that refunding it is
        // the owner's in their provider's dashboard: the two paragraphs that say so.
        ->and($text)->toContain(businessMailLine('business-mail.late_payment.arrived', ['business' => $tenant->name]))
        ->and($text)->toContain(businessMailLine('business-mail.late_payment.stands'))
        ->and(array_filter($secrets, fn (string $secret): bool => str_contains($text.$this->log->text(), $secret)))->toBe([])
        // The owner looks for the booking at the provider, not the payment: its id is not in the
        // message, in either body, whether or not the notice named it.
        ->and(str_contains($text.$mail->getHtmlBody(), 'pd_late'))->toBeFalse()
        ->and(lateFlagsAsked())->toHaveCount($payment === null ? 0 : 1)
        ->and(array_filter($this->log->records, fn (array $line): bool => $line['message'] === PaymentWebhook::LATE))->toHaveCount(1);
})->with(['the notice names the payment' => ['pd_late'], 'the notice names none' => [null]]);

it('sends the late payment\'s notice under late_payment and under no other key, and a tenant who switched that key off is not sent it', function (bool $off) {
    // What a message is keyed is not visible in the mail it becomes, so it is asked of the one
    // thing that reads the key: the switches.
    seamSwitchOff($off ? [MessageKey::LatePayment->value] : seamEveryKeyBut(MessageKey::LatePayment));
    $held = lateHold($this);

    deliverPaydesk($this, paydeskBody('evt_late', $held['uuid'], payment: 'pd_late'))->assertOk();

    expect(array_map(fn ($mail): string => (string) $mail->getSubject(), seamSent()))->toHaveCount($off ? 0 : 1)
        ->and(lateFlagsAsked())->toHaveCount(1);
})->with(['every other key off' => [false], 'late_payment off' => [true]]);

it('queues nothing and tells nobody where a concurrent delivery of the same event marked it between the handling and the mark', function () {
    $held = lateHold($this);
    $reached = [];
    Checkpoint::observe(function (string $name) use (&$reached): void {
        $reached[] = $name;

        if ($name === PaymentWebhook::HANDLED) {
            DB::table('payment_events')->where('event_id', 'evt_late')->update(['processed_at' => DB::raw('now()')]);
        }
    });

    deliverPaydesk($this, paydeskBody('evt_late', $held['uuid'], payment: 'pd_late'))->assertOk();

    expect(array_values(array_filter($reached, fn (string $name): bool => $name === PaymentWebhook::HANDLED)))->toBe([PaymentWebhook::HANDLED])
        ->and(paymentEventRows()[0]['processed_at'])->not->toBeNull()
        ->and(lateFlagsAsked())->toBe([])
        ->and(seamSent())->toBe([])
        ->and(array_filter($this->log->records, fn (array $line): bool => $line['message'] === PaymentWebhook::LATE))->toHaveCount(1);
});

it('logs a late payment\'s note it could not queue as LATE_UNQUEUED, naming the class alone, never throws it back over the marked event, and the owner\'s message keeps a guard of its own', function () {
    $held = lateHold($this);
    config(['queue.default' => 'nowhere']);

    deliverPaydesk($this, paydeskBody('evt_late', $held['uuid'], payment: 'pd_late'))->assertOk();

    $errors = array_values(array_filter($this->log->records, fn (array $line): bool => $line['level'] === 'error'));

    expect(paymentEventRows()[0]['processed_at'])->not->toBeNull()
        ->and(lateFlagsAsked())->toBe([])
        ->and(seamSent())->toBe([])
        ->and(array_map(fn (array $line): mixed => $line['message'], $errors))->toBe([PaymentWebhook::LATE_UNQUEUED, 'A message could not be queued'])
        ->and($errors[0]['context'] ?? null)->toBe(['tenant' => 'luna', 'event' => 'evt_late', 'booking' => $held['uuid'], 'exception' => InvalidArgumentException::class]);
});

it('records and marks a notice that is not a payment, and acts on nothing', function () {
    $held = paidHold($this);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid'], 'payment.created'))->assertOk();

    expect(paidRow($held['uuid'])['status'])->toBe('pending')
        ->and(paymentEventRows()[0]['type'])->toBe('payment.created')
        ->and(paymentEventRows()[0]['processed_at'])->not->toBeNull();
});

it('answers a handling failure with the envelope\'s 500 whatever the debug setting, leaves the event unmarked, reports it with its trace, and the retry lands it', function (bool $debug) {
    config(['app.debug' => $debug]);
    $held = paidHold($this);
    $body = paydeskBody('evt_1', $held['uuid']);
    Checkpoint::observe(function (string $name): void {
        if ($name === GuardedWrite::AFTER_LOCK) {
            throw new RuntimeException('The lock went away.');
        }
    });

    deliverPaydesk($this, $body)
        ->assertStatus(500)
        ->assertExactJson(['code' => 'server_error', 'message' => 'server_error', 'data' => ['status' => 500, 'detail' => apiSentence(ErrorEnvelope::SERVER_ERROR_DETAIL)]]);

    $reported = $this->log->records[1]['context']['exception'] ?? null;
    $cause = $reported instanceof Throwable ? $reported->getPrevious() : null;

    expect(paymentEventRows()[0]['processed_at'])->toBeNull()
        ->and(paidRow($held['uuid'])['status'])->toBe('pending')
        ->and($this->log->records[0])->toBe([
            'level' => 'error',
            'message' => PaymentWebhook::UNHANDLED,
            'context' => ['provider' => FakePaymentAdapter::SLUG, 'event' => 'evt_1', 'exception' => RuntimeException::class],
        ])
        ->and($reported)->toBeInstanceOf(NotificationUnhandled::class)
        ->and($cause?->getMessage())->toBe('The lock went away.')
        ->and($cause?->getTrace())->not->toBeEmpty();

    Checkpoint::observe(null);
    deliverPaydesk($this, $body)->assertOk();

    expect(paidRow($held['uuid'])['status'])->toBe('confirmed')
        ->and(paymentEventRows())->toHaveCount(1)
        ->and(paymentEventRows()[0]['processed_at'])->not->toBeNull()
        ->and(paidAudits($held['uuid']))->toBe(1);
})->with(['debug on' => [true], 'debug off' => [false]]);

it('confirms the booking of a tenant whose subscription is past due, canceled or incomplete', function (string $state) {
    $held = paidHold($this);
    billingSet(['subscription_state' => $state]);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();

    expect(paidRow($held['uuid'])['status'])->toBe('confirmed');
})->with(['past_due', 'canceled', 'incomplete']);

it('reads and writes nothing of billing\'s webhook_events', function () {
    $held = paidHold($this);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();
    deliverPaydesk($this, paydeskBody('evt_2', $held['uuid']), 'bad');

    expect(implode("\n", $statements))->not->toContain('webhook_events')
        ->and(DB::table('webhook_events')->count())->toBe(0);
});

it('carries the manage link the hop saw into the confirmation, sealed at rest until the transition clears it, and numbers the calendar file by the reader', function () {
    $held = paidHold($this);
    $sealed = paidRow($held['uuid'])['manage_token_sealed'];

    assert(is_string($sealed));

    expect($sealed)->not->toContain($held['manage_token'])
        ->and(base64_decode($sealed, true))->not->toContain($held['manage_token'])
        ->and(Crypt::decryptString($sealed))->toBe($held['manage_token']);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();

    $message = seamAt('casey@example.com');
    // Under the prefix of the booking's language, English here.
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['manage_token'], SiteLanguage::English);
    $files = $message->getAttachments();
    $revision = app(BookingReader::class)->revision(Tenant::query()->where('slug', 'luna')->sole(), $held['uuid']);
    $row = paidRow($held['uuid']);

    expect((string) $message->getTextBody())->toContain($link)
        ->and($row['manage_token_sealed'])->toBeNull()
        ->and(json_encode($row))->not->toContain($held['manage_token'])
        ->and($revision)->toBeGreaterThan(0)
        ->and($files)->toHaveCount(1)
        ->and($files[0]->getBody())->toContain(icsSequenceLine($revision));
});

it('says where the customer\'s link is when no token was seen, and carries none', function () {
    $held = paidHold($this);
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['manage_token_sealed' => null]);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();

    $body = (string) seamAt('casey@example.com')->getTextBody();

    // The two links are Book again's and the closing line's, to the booking page preselecting
    // Colour on Elena and to My bookings, and neither carries a credential: no manage link is
    // built without the token.
    expect($body)->toContain(customerMailLine('customer-mail.confirmed.where_link.unacknowledged', ['business' => 'Luna Hair Studio']))
        ->and(seamLinksIn($body))->toBe(paidLinksWithoutAToken());
});

it('clears the sealed token when the hold ends by any road, leaving no recoverable form of it at rest but the hash the guard checks', function (string $road) {
    $held = paidHold($this);

    expect(paidRow($held['uuid'])['manage_token_sealed'])->toBeString();

    match ($road) {
        'released' => $this->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$held['uuid'].'?token='.$held['manage_token']))->assertSuccessful(),
        'cancelled' => $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/cancel'), ['token' => $held['manage_token']])->assertSuccessful(),
        'lapsed and swept' => (function () use ($held): void {
            DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
            app(ExpireHoldsFactory::class)->for(Tenant::query()->where('slug', 'luna')->sole())->sweep();
        })(),
        'lapsed and reaped by a new hold' => (function () use ($held): void {
            // The lapse is written behind the availability cache's back, which still counts the
            // hold busy; a cold read sees it lapsed, as the hold's own reap does under the lock.
            DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 second'")]);
            Cache::flush();
            paidHold($this, 'Colour', false);
        })(),
        default => null,
    };

    $row = paidRow($held['uuid']);
    $atRest = json_encode(array_diff_key($row, ['manage_token_hash' => true]));

    expect($row['status'])->toBeIn(['cancelled', 'expired'])
        ->and($row['manage_token_sealed'])->toBeNull()
        ->and($row['manage_token_hash'])->toBeString()
        ->and($atRest)->not->toContain($held['manage_token'])
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['released', 'cancelled', 'lapsed and swept', 'lapsed and reaped by a new hold']);

it('confirms a booking awaiting its payment after an approval, and with no token seen points the customer at the acknowledgement\'s link', function () {
    $held = paidHold($this);
    DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->update(['status' => 'awaiting_payment', 'hold_class' => 'payment', 'manage_token_sealed' => null]);

    deliverPaydesk($this, paydeskBody('evt_1', $held['uuid']))->assertOk();

    $row = paidRow($held['uuid']);
    $body = (string) seamAt('casey@example.com')->getTextBody();

    expect([$row['status'], $row['hold_class'], $row['hold_expires_at']])->toBe(['confirmed', null, null])
        ->and($body)->toContain(customerMailLine('customer-mail.confirmed.where_link.acknowledged'))
        // The two links are Book again's and the closing line's, neither carrying a credential.
        ->and(seamLinksIn($body))->toBe(paidLinksWithoutAToken());
});
