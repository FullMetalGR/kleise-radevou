<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\GuardedWrite;
use App\Application\Payment\CheckoutNotOpened;
use App\Application\Payment\ConnectionState;
use App\Domain\Audit\Actor;
use App\Http\BookingPage;
use App\Http\CustomerNotice;
use App\Http\PayHopAnswer;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\PostgresCheckoutRecords;
use App\Infrastructure\Db\UniqueViolation;
use App\Site\SiteLanguage;
use App\Tenancy\PayLinks;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The paid checkout on luna's real rows: checkout_url on the hold answer, and the pay hop it
 * names, GET /pay/{uuid}, through to the provider's page and back to the manage page. luna's
 * Colour is an online service, Haircut a pay-on-site one and Bridal trial an online one that
 * asks for approval. The fake is connected and active unless a case says otherwise; its session
 * ends 900 seconds after the end it is asked for.
 *
 * Not wrapped in a transaction: a hold enters the transaction runner, which refuses to open
 * inside another, and the fake records whether a transaction was open when it was asked, which
 * only means something here. The hold's expiry is the database's now() plus fifteen minutes,
 * the clock the hop's liveness reads; the pinned clock is the one the hold's start and the
 * manage token's lifetime read.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
});

afterEach(function () {
    Checkpoint::observe(null);
    DB::purge('hop_probe');
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * @return array{uuid: string, manage_token: string, total_minor: int, currency: string, checkout_url?: string, pays_at_venue?: bool}
 */
function hopHold(TestCase $test, string $service = 'Colour', string $start = FixtureDay::START): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $ids('services', $service), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']) && is_int($held['total_minor']) && is_string($held['currency']));
    $shape = ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token'], 'total_minor' => $held['total_minor'], 'currency' => $held['currency']];

    if (isset($held['pays_at_venue']) && is_bool($held['pays_at_venue'])) {
        $shape['pays_at_venue'] = $held['pays_at_venue'];
    }

    return isset($held['checkout_url']) && is_string($held['checkout_url']) ? [...$shape, 'checkout_url' => $held['checkout_url']] : $shape;
}

/**
 * @param  array{uuid: string, manage_token: string}  $held
 * @return TestResponse<Response>
 */
function hopVisit(TestCase $test, array $held, ?string $token = null): TestResponse
{
    return $test->get(tenantUrl('luna', '/pay/'.$held['uuid'].'?'.http_build_query(['token' => $token ?? $held['manage_token']])));
}

/**
 * The manage link for the hold, bare as the hop's redirect answers a bare visit, or under the
 * prefix of the language named, as the confirmation mints it under the booking's language.
 *
 * @param  array{uuid: string, manage_token: string}  $held
 */
function hopManageLink(array $held, ?SiteLanguage $language = null): string
{
    return app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['manage_token'], $language);
}

/**
 * The columns the hop may write, read raw, the expiry as UTC text.
 *
 * @param  array{uuid: string}  $held
 * @return array{status: string, payment_ref: string|null, hold_expires_at: string|null}
 */
function hopRow(array $held): array
{
    $row = (array) DB::selectOne("select status, payment_ref, to_char(hold_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as hold_expires_at from tenant_luna.bookings where uuid = ?", [$held['uuid']]);
    $text = fn (string $key): ?string => isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;

    return ['status' => (string) $text('status'), 'payment_ref' => $text('payment_ref'), 'hold_expires_at' => $text('hold_expires_at')];
}

function hopUtc(DateTimeImmutable $instant): string
{
    return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

it('answers checkout_url on the hold of an online service while the fake is connected and active, the hop on the tenant\'s host carrying the booking and its token, and asks the provider nothing', function () {
    connectPaymentFake();
    $held = hopHold($this);

    expect($held['checkout_url'] ?? null)->toBe(tenantUrl('luna', '/pay/'.$held['uuid'].'?token='.$held['manage_token']))
        ->and(paymentFake()->asked)->toBe([])
        ->and(paymentFake()->created())->toBe(0)
        ->and(hopRow($held)['payment_ref'])->toBeNull()
        ->and($held)->not->toHaveKey('pays_at_venue');
});

it('leaves checkout_url off the hold of a free service, a pay-on-site one, an online one under the null provider and an online one awaiting approval', function (string $service, ?string $mode, ?ConnectionState $state, bool $atVenue) {
    if ($mode !== null) {
        DB::table('tenant_luna.services')->where('name', $service)->update(['payment_mode' => $mode]);
    }

    if ($state !== null) {
        connectPaymentFake($state);
    }

    $held = hopHold($this, $service);

    expect($held)->not->toHaveKey('checkout_url')
        ->and($held['pays_at_venue'] ?? null)->toBe($atVenue ? true : null)
        ->and(paymentFake()->asked)->toBe([]);
})->with([
    'free' => ['Haircut', 'free', ConnectionState::Active, false],
    'pay on site' => ['Haircut', null, ConnectionState::Active, false],
    'online with nothing connected' => ['Colour', null, null, true],
    'online with the connection disabled' => ['Colour', null, ConnectionState::Disabled, true],
    'online awaiting approval' => ['Bridal trial', null, ConnectionState::Active, false],
]);

it('sends a live hold on to the provider\'s page, writing the session\'s id to payment_ref and moving the hold\'s expiry to the session\'s end in the same write, with no transaction open while the provider was asked', function () {
    connectPaymentFake();
    $held = hopHold($this);
    $before = hopRow($held);

    $response = hopVisit($this, $held);
    $fake = paymentFake();
    $session = $fake->sessions['pd_1'] ?? null;

    assert($session !== null);

    $response->assertStatus(303)
        ->assertRedirect(hopManageLink($held).'&paydesk_session=pd_1')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect(hopRow($held))->toBe(['status' => 'pending', 'payment_ref' => 'pd_1', 'hold_expires_at' => hopUtc($session['endsAt'])])
        ->and(hopUtc($session['askedEnd']))->toBe($before['hold_expires_at'])
        ->and($session['endsAt']->getTimestamp() - $session['askedEnd']->getTimestamp())->toBe(900)
        ->and($session['returnUrl'])->toBe(hopManageLink($held))
        ->and($session['total']->minor)->toBe($held['total_minor'])
        ->and($session['total']->currency->code)->toBe($held['currency'])
        ->and($fake->created())->toBe(1)
        ->and($fake->transactionOpen)->toBe([false]);
});

it('reuses the session on a second visit: the same page, no second session asked for, and nothing moved', function () {
    connectPaymentFake();
    $held = hopHold($this);

    $first = hopVisit($this, $held)->assertStatus(303)->headers->get('Location');
    $afterFirst = hopRow($held);
    $second = hopVisit($this, $held)->assertStatus(303)->headers->get('Location');

    expect($second)->toBe($first)
        ->and(hopRow($held))->toBe($afterFirst)
        ->and(paymentFake()->created())->toBe(1)
        ->and(paymentFake()->asked)->toBe([['checkoutUrl', $held['uuid']], ['checkoutUrl', $held['uuid']]]);
});

it('sends a press again after the customer paid and before the payment\'s notification landed back to the manage page with a flash saying the payment is on its way, never the 502 page, asking the provider once and writing nothing', function () {
    Exceptions::fake();
    connectPaymentFake();
    $held = hopHold($this);
    hopVisit($this, $held)->assertStatus(303);
    $paying = hopRow($held);
    paymentFake()->completed[] = 'pd_1';

    hopVisit($this, $held)->assertStatus(303)
        ->assertRedirect(hopManageLink($held))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'info', 'message' => PayHopAnswer::PAYMENT_ON_ITS_WAY]);

    Exceptions::assertNothingReported();

    // The manage page the customer lands on reads the sentence once, and a reload reads none.
    $landed = documentOf($this->get(hopManageLink($held))->assertOk())->getElementById(BookingPage::NOTICE_ID)?->textContent;
    $reloaded = documentOf($this->get(hopManageLink($held))->assertOk())->getElementById(BookingPage::NOTICE_ID);

    expect(hopRow($held))->toBe($paying)
        ->and(paymentFake()->created())->toBe(1)
        ->and(paymentFake()->asked)->toBe([['checkoutUrl', $held['uuid']], ['checkoutUrl', $held['uuid']]])
        ->and(json_decode((string) $landed, true))->toBe(['notice' => pageSentence(PayHopAnswer::PAYMENT_ON_ITS_WAY)])
        ->and((string) $landed)->not->toContain($held['manage_token'])
        ->and($reloaded)->toBeNull();
});

it('shortens the hold to a session the provider ends before it, since the two end together', function () {
    connectPaymentFake();
    paymentFake()->sessionOverrunSeconds = -300;
    $held = hopHold($this);
    $before = hopRow($held);

    hopVisit($this, $held)->assertStatus(303);

    $after = new DateTimeImmutable((string) hopRow($held)['hold_expires_at'], new DateTimeZone('UTC'));

    expect($after->getTimestamp() - (new DateTimeImmutable((string) $before['hold_expires_at'], new DateTimeZone('UTC')))->getTimestamp())->toBe(-300);
});

it('refuses a hold that lapsed, was swept, was released or was cancelled with a worded page before the provider is asked, and writes nothing', function (Closure $end) {
    connectPaymentFake();
    $held = hopHold($this);
    $end($this, $held['uuid'], $held['manage_token']);
    $before = hopRow($held);

    hopVisit($this, $held)->assertStatus(409)
        ->assertSee(pageSentence('pages.pay.ended.title'))
        ->assertSee(pageSentence('pages.pay.ended.given_back'))
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(paymentFake()->asked)->toBe([])
        ->and(hopRow($held))->toBe($before)
        ->and($before['payment_ref'])->toBeNull();
})->with([
    'lapsed by the clock, not yet swept' => [fn (TestCase $test, string $uuid) => DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?", [$uuid])],
    'swept to expired' => [fn (TestCase $test, string $uuid) => DB::update("update tenant_luna.bookings set status = 'expired', hold_class = null, hold_expires_at = null where uuid = ?", [$uuid])],
    'released by the customer' => [fn (TestCase $test, string $uuid, string $token) => $test->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$uuid.'?token='.$token))->assertSuccessful()],
    'cancelled' => [fn (TestCase $test, string $uuid) => DB::update("update tenant_luna.bookings set status = 'cancelled', hold_class = null, hold_expires_at = null where uuid = ?", [$uuid])],
]);

it('sends a booking already confirmed to its manage page and asks the provider nothing', function () {
    connectPaymentFake();
    $held = hopHold($this);
    DB::update("update tenant_luna.bookings set status = 'confirmed', hold_class = null, hold_expires_at = null where uuid = ?", [$held['uuid']]);

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held));

    expect(paymentFake()->asked)->toBe([]);
});

it('answers a wrong, an expired or a missing token and an unknown booking as the manage guard answers them everywhere, one body for every failed credential', function () {
    connectPaymentFake();
    $held = hopHold($this);
    $api = fn (string $query): TestResponse => $this->get(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].$query));

    $wrong = hopVisit($this, $held, 'not-the-token')->assertStatus(404)->assertSee(pageSentence('pages.pay.refused.title'));
    $unknown = $this->get(tenantUrl('luna', '/pay/00000000-0000-4000-8000-000000000000?token='.$held['manage_token']))->assertStatus(404);
    $missing = $this->get(tenantUrl('luna', '/pay/'.$held['uuid']))->assertStatus(400)->assertSee(pageSentence('pages.pay.refused.title'));
    $api('?token=not-the-token')->assertStatus(404);
    $api('')->assertStatus(400)->assertJsonPath('code', 'invalid_request');
    app()->instance(Clock::class, FixedClock::at('2026-12-31 09:00:00'));
    $expired = hopVisit($this, $held)->assertStatus(404);
    $api('?token='.$held['manage_token'])->assertStatus(404);

    expect($unknown->getContent())->toBe($wrong->getContent())
        ->and($expired->getContent())->toBe($wrong->getContent())
        ->and($missing->getContent())->toBe($wrong->getContent())
        ->and(paymentFake()->asked)->toBe([])
        ->and(hopRow($held)['payment_ref'])->toBeNull();
});

it('reports a provider that failed once, with no credential and no token in the report, answers a worded page, writes nothing and leaves the hold as it was', function () {
    Exceptions::fake();
    connectPaymentFake();
    $held = hopHold($this);
    $before = hopRow($held);
    paymentFake()->failWith = 'Paydesk refused merchant '.paymentFakeCredentials()['merchant_code'].' with token '.paymentFakeCredentials()['api_token'].' returning to '.hopManageLink($held);

    hopVisit($this, $held)->assertStatus(502)
        ->assertHeaderMissing('Retry-After')
        ->assertSee(pageSentence('pages.pay.unopened.title'))
        ->assertSee(pageSentence('pages.pay.unopened.again'));

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(function (CheckoutNotOpened $report) use ($held): bool {
        $text = $report->getMessage().$report->getTraceAsString();

        foreach ([...array_values(paymentFakeCredentials()), $held['manage_token'], 'Paydesk refused'] as $secret) {
            if (str_contains($text, $secret)) {
                return false;
            }
        }

        return $report->getPrevious() === null && str_contains($report->getMessage(), $held['uuid']);
    });

    expect(hopRow($held))->toBe($before);
});

it('answers a provider that answered nowhere to go with the worded page at 502 and no Retry-After, asked once and no session opened, reports nothing, writes nothing and leaves the hold as it was', function () {
    Exceptions::fake();
    connectPaymentFake();
    $held = hopHold($this);
    $before = hopRow($held);
    paymentFake()->answersNowhere = true;

    hopVisit($this, $held)->assertStatus(502)
        ->assertHeaderMissing('Retry-After')
        ->assertSee(pageSentence('pages.pay.unopened.title'))
        ->assertSee(pageSentence('pages.pay.unopened.again'));

    Exceptions::assertNothingReported();

    // Asked once, outside any transaction, and no session opened.
    expect(paymentFake()->asked)->toBe([['checkoutUrl', $held['uuid']]])
        ->and(paymentFake()->transactionOpen)->toBe([false])
        ->and(paymentFake()->created())->toBe(0)
        ->and(hopRow($held))->toBe($before);
});

it('does not revive a hold that ended while the provider was being asked, and writes nothing', function (string $ending) {
    connectPaymentFake();
    $held = hopHold($this);
    paymentFake()->whileAsked = fn () => DB::update($ending, [$held['uuid']]);

    hopVisit($this, $held)->assertStatus(409)->assertSee(pageSentence('pages.pay.ended.title'));

    $row = hopRow($held);

    expect($row['payment_ref'])->toBeNull()
        ->and(paymentFake()->created())->toBe(1)
        ->and((array) DB::selectOne('select hold_expires_at is null or hold_expires_at <= now() as ended from tenant_luna.bookings where uuid = ?', [$held['uuid']]))->toBe(['ended' => true]);
})->with([
    'lapsed' => ["update tenant_luna.bookings set hold_expires_at = now() - interval '1 second' where uuid = ?"],
    'swept' => ["update tenant_luna.bookings set status = 'expired', hold_class = null, hold_expires_at = null where uuid = ?"],
    'cancelled' => ["update tenant_luna.bookings set status = 'cancelled', hold_class = null, hold_expires_at = null where uuid = ?"],
]);

it('brings the customer back to the manage page with the token, which reads the booking as it stands whatever the provider added to the address', function () {
    connectPaymentFake();
    $held = hopHold($this);
    $page = hopVisit($this, $held)->headers->get('Location');
    assert(is_string($page));

    $this->get($page.'&status=confirmed&paid=1&redirect_status=succeeded')->assertOk();
    $read = $this->get(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$held['manage_token'].'&status=confirmed&paid=1'))->assertOk()->json('status');

    expect(str_starts_with($page, hopManageLink($held)))->toBeTrue()
        ->and($read)->toBe('pending')
        ->and(hopRow($held)['status'])->toBe('pending');
});

it('opens the hop while the subscription is lapsed, canceled or incomplete: paying is a guest route and never frozen', function (string $state) {
    connectPaymentFake();
    $held = hopHold($this);
    billingSet(['subscription_state' => $state]);

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held).'&paydesk_session=pd_1');

    expect(hopRow($held)['payment_ref'])->toBe('pd_1');
})->with(['past_due', 'canceled', 'incomplete']);

it('settles the live hold on site through the shared guarded write when the connection stopped working between the hold and the press, sends the customer to the manage page reading confirmed, told they pay at the venue, with the link in the confirmation, and a second visit finds it confirmed and is told nothing', function () {
    connectPaymentFake();
    $held = hopHold($this);
    connectPaymentFake(ConnectionState::Disabled);
    seamTransport()->flush();

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held))
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'success', 'message' => PayHopAnswer::PAY_AT_THE_VENUE]);

    expect(hopRow($held))->toMatchArray(['status' => 'confirmed', 'payment_ref' => null, 'hold_expires_at' => null])
        ->and(seamSent()[0]->getSubject())->toBe(customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and(seamLinksIn((string) seamSent()[0]->getTextBody()))->toContain(hopManageLink($held, SiteLanguage::English));

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held))->assertSessionMissing(CustomerNotice::KEY);

    expect(paymentFake()->asked)->toBe([])
        ->and(hopRow($held)['status'])->toBe('confirmed')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1);
});

it('settles on site at a second visit where the connection stopped working after the first opened a session, keeping payment_ref as recorded and asking no provider, sends the customer to the manage page told they pay at the venue with the link in the confirmation, and a third visit finds it confirmed', function (Closure $stop) {
    connectPaymentFake();
    $held = hopHold($this);
    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held).'&paydesk_session=pd_1');
    $asked = paymentFake()->asked;
    $stop();
    seamTransport()->flush();

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held))
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'success', 'message' => PayHopAnswer::PAY_AT_THE_VENUE]);

    expect(hopRow($held))->toMatchArray(['status' => 'confirmed', 'payment_ref' => 'pd_1', 'hold_expires_at' => null])
        ->and(seamSent()[0]->getSubject())->toBe(customerMailLine('customer-mail.confirmed.subject', ['business' => 'Luna Hair Studio']))
        ->and(seamLinksIn((string) seamSent()[0]->getTextBody()))->toContain(hopManageLink($held, SiteLanguage::English))
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1)
        ->and(paymentFake()->asked)->toBe($asked);

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held));

    expect(hopRow($held)['status'])->toBe('confirmed')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1)
        ->and(paymentFake()->asked)->toBe($asked)
        ->and(paymentFake()->created())->toBe(1);
})->with([
    'the row disabled' => [fn () => connectPaymentFake(ConnectionState::Disabled)],
    'its check failed' => [fn () => connectPaymentFake(ConnectionState::Active, false)],
]);

it('answers an on-site settlement that lost to a move under the lock as the decide links answer one, sending the customer back to the manage page to read the booking as it stands, told that nothing was done, rather than to the framework\'s error page', function () {
    connectPaymentFake();
    $held = hopHold($this);
    connectPaymentFake(ConnectionState::Disabled);
    $moves = 0;

    // A concurrent reschedule at the checkpoint between the staff-day locks and the booking
    // row, on a connection of its own so it commits under the write, at every attempt: the
    // write answers busy.
    Checkpoint::observe(function (string $name) use ($held, &$moves): void {
        if ($name !== GuardedWrite::AFTER_LOCK) {
            return;
        }

        $moves++;

        LunaFixture::probe('hop_probe')->update(
            "update tenant_luna.booking_items set start_utc = start_utc + interval '1 day',"
            ." end_utc = end_utc + interval '1 day', block_start_utc = block_start_utc + interval '1 day',"
            ." block_end_utc = block_end_utc + interval '1 day', processing_ends_utc = processing_ends_utc + interval '1 day'"
            .' from tenant_luna.bookings b where b.id = booking_id and b.uuid = ?::uuid',
            [$held['uuid']],
        );
    });

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held))
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'warning', 'message' => PayHopAnswer::SETTLEMENT_LOST]);
    Checkpoint::observe(null);

    expect($moves)->toBe(GuardedWrite::ATTEMPTS)
        ->and(hopRow($held)['status'])->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0)
        ->and(paymentFake()->asked)->toBe([]);
});

it('answers a first press whose write met the staff-day held past the lock timeout as a lost race: the row re-read, the unopened page\'s words answered at 503 with a Retry-After, nothing written, the runner\'s line the one log record, and a press again opens the payment', function () {
    connectPaymentFake();
    $held = hopHold($this);
    $before = hopRow($held);
    $log = runnerLog();
    $probe = bookingStaffDaysHeld('hop_probe', $held['uuid']);

    try {
        $busy = hopVisit($this, $held);
    } finally {
        $probe->rollBack();
    }

    // Lock contention, the house's 503 busy, not the 502 a provider's failure answers.
    $busy->assertStatus(503)->assertHeader('Retry-After', '1')->assertSee(pageSentence('pages.pay.unopened.title'))->assertSee(pageSentence('pages.pay.unopened.again'));
    $lines = $log->getRecords();

    // The session the press opened is orphaned, as a lapsed hold orphans one; the hold, the
    // reference and the seal stand as they were. The runner's Busy line is the request's one
    // log record: nothing reports it again.
    expect(hopRow($held))->toBe($before)
        ->and(DB::scalar('select manage_token_sealed is null from tenant_luna.bookings where uuid = ?', [$held['uuid']]))->toBeTrue()
        ->and(paymentFake()->created())->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->context['failure'] ?? null)->toBe(Busy::class)
        ->and($lines[0]->context['sqlstate'] ?? null)->toBe('55P03');

    hopVisit($this, $held)->assertStatus(303);

    expect(hopRow($held)['payment_ref'])->not->toBeNull()
        ->and(paymentFake()->created())->toBe(2);
});

it('answers a first press whose write lost to a payment that confirmed the booking between the provider\'s answer and the hop\'s write by sending the customer to the manage page, as the first read answers a settled booking, nothing written', function () {
    connectPaymentFake();
    $held = hopHold($this);
    $settled = 0;

    // The payment lands between the provider's answer and the hop's write: the guarded update
    // finds the booking no longer payable and writes nothing, and the row read again is settled.
    Checkpoint::observe(function (string $name) use ($held, &$settled): void {
        if ($name !== PostgresCheckoutRecords::KEYED || $settled > 0) {
            return;
        }

        $settled++;
        app(BookingTransitionFactory::class)->paid(LunaFixture::tenant())->confirm($held['uuid']);
    });

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held));
    Checkpoint::observe(null);

    expect($settled)->toBe(1)
        ->and(hopRow($held)['status'])->toBe('confirmed')
        ->and(hopRow($held)['payment_ref'])->toBeNull()
        ->and(paymentFake()->created())->toBe(1);
});

it('answers an on-site settlement whose write met the staff-day held past the lock timeout as one that lost a race, sending the customer back to the manage page to read the booking as it stands, told that nothing was done, nothing written', function () {
    connectPaymentFake();
    $held = hopHold($this);
    connectPaymentFake(ConnectionState::Disabled);
    $before = hopRow($held);
    $log = runnerLog();
    $probe = bookingStaffDaysHeld('hop_probe', $held['uuid']);

    try {
        $busy = hopVisit($this, $held);
    } finally {
        $probe->rollBack();
    }

    $busy->assertStatus(303)->assertRedirect(hopManageLink($held))->assertHeaderMissing('Retry-After')
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'warning', 'message' => PayHopAnswer::SETTLEMENT_LOST]);
    $lines = $log->getRecords();

    expect(hopRow($held))->toBe($before)
        ->and($before['status'])->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->context['failure'] ?? null)->toBe(Busy::class)
        ->and($lines[0]->context['sqlstate'] ?? null)->toBe('55P03')
        ->and(paymentFake()->asked)->toBe([]);

    hopVisit($this, $held)->assertStatus(303)->assertRedirect(hopManageLink($held))
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'success', 'message' => PayHopAnswer::PAY_AT_THE_VENUE]);

    expect(hopRow($held)['status'])->toBe('confirmed');
});

it('answers a press after a move cleared the session, on a hold too short for any session the provider would open, with a page of its own at 409 saying it can no longer be paid online, never the 502 page and never "try again", reporting nothing and writing nothing', function () {
    Exceptions::fake();
    connectPaymentFake();
    $held = hopHold($this);
    hopVisit($this, $held)->assertStatus(303);
    app(BookingTransitionFactory::class)->reschedule(LunaFixture::tenant())->reschedule($held['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 15:00'));
    $moved = hopRow($held);
    paymentFake()->laterSessionTooShort = true;

    hopVisit($this, $held)->assertStatus(409)
        ->assertHeaderMissing('Retry-After')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee(pageSentence('pages.pay.closed.title'))
        ->assertSee(pageSentence('pages.pay.closed.held'))
        ->assertDontSee(pageSentence('pages.pay.unopened.title'))
        ->assertDontSee(pageSentence('pages.pay.unopened.again'))
        ->assertSessionMissing(CustomerNotice::KEY);

    Exceptions::assertNothingReported();

    expect($moved)->toMatchArray(['status' => 'pending', 'payment_ref' => null])
        ->and(hopRow($held))->toBe($moved)
        ->and(paymentFake()->created())->toBe(1)
        ->and(paymentFake()->expired)->toBe(['pd_1'])
        ->and(paymentFake()->sessions)->toHaveCount(1);
});

it('lets a failure that is no race, at the hop\'s write and at the on-site settlement alike, reach the handler: reported once as what it is, answered as the framework\'s fault, nothing written, never read as a lost race', function (bool $onSite) {
    Exceptions::fake();
    connectPaymentFake();
    $held = hopHold($this);

    if ($onSite) {
        connectPaymentFake(ConnectionState::Disabled);
    }

    $before = hopRow($held);

    // Any update of a booking row raises a unique-key violation inside the write's transaction:
    // a TransactionFailure of SqlState's table that is neither Busy nor retries exhausted.
    DB::unprepared(<<<'SQL'
        create function tenant_luna.refuse_update() returns trigger language plpgsql as $$
        begin raise exception 'refused by the test' using errcode = '23505'; end $$;
        create trigger refuse_update before update on tenant_luna.bookings for each row execute function tenant_luna.refuse_update();
        SQL);

    hopVisit($this, $held)->assertStatus(500)->assertHeaderMissing('Retry-After');

    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(UniqueViolation::class);

    expect(hopRow($held))->toBe($before)
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0)
        ->and(paymentFake()->created())->toBe($onSite ? 0 : 1);
})->with([
    'the hop\'s write recording the session' => [false],
    'the on-site settlement' => [true],
]);

it('leaves no credential in the sessions table, the database session writing a row for every request as it does on the host: a pay visit, the manage page it sends the customer to, the manage view\'s two reads and the payment link\'s status page each keep their URL out of the previous-URL slot (P9F-L16-b)', function () {
    config(['session.driver' => 'database']);
    connectPaymentFake();
    $held = hopHold($this);
    $token = $held['manage_token'];
    $link = app(PayLinks::class)->link(LunaFixture::tenant(), $held['uuid'], FixtureDay::start()->add(new DateInterval('PT1H')));
    $signature = (string) Str::after($link, 'signature=');
    DB::table('sessions')->delete();

    // Each request a browser of its own, carrying no session cookie: a fresh store and handler
    // before every one, so each writes a row of its own, as on the host. (The test client
    // otherwise carries one store across its requests, whose handler updates the first row's id
    // alone.)
    $requests = [
        fn () => hopVisit($this, $held)->assertStatus(303),
        fn () => $this->get(hopManageLink($held).'&paydesk_session=pd_1')->assertOk(),
        fn () => $this->get(hopManageLink($held))->assertOk(),
        fn () => $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'?token='.$token))->assertOk(),
        fn () => $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/availability?'.http_build_query(['token' => $token, 'from' => '2026-11-04', 'to' => '2026-11-05'])))->assertOk(),
        fn () => $this->get($link)->assertOk(),
    ];

    foreach ($requests as $request) {
        app('session')->forgetDrivers();
        $request();
    }

    $rows = array_map(fn (mixed $payload): string => is_string($payload) ? (string) base64_decode($payload, true) : '', DB::table('sessions')->pluck('payload')->all());

    expect($rows)->toHaveCount(6)
        ->and(array_filter($rows, fn (string $row): bool => $row === ''))->toBe([])
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, $token)))->toBe([])
        ->and(array_filter($rows, fn (string $row): bool => str_contains($row, $signature)))->toBe([])
        ->and($signature)->not->toBe('');
});
