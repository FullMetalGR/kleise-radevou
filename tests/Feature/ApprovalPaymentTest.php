<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\GuardedWrite;
use App\Application\Notification\MessageKey;
use App\Application\Payment\ConnectionState;
use App\Domain\Audit\Actor;
use App\Domain\Availability\Granularity;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\CustomerNotice;
use App\Http\PayHopAnswer;
use App\Http\PayPages;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\ExpireHoldsFactory;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\PayLinks;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Tests\Support\FakePaymentAdapter;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The other road to a paid booking, on luna's real rows: a request lodged for Bridal trial, an
 * online service that asks for approval, approved by the owner, then paid through the signed
 * link the approved message carries. Haircut is a pay-on-site service and stands in for every
 * booking an approval lands confirmed.
 *
 * Not wrapped in a transaction: an approval enters the transaction runner, which refuses to open
 * inside another. The payment hold's expiry is the database's now() plus the settings row's
 * payment hold; the pinned clock is the one the hold's start and the approval stamp read, a
 * fixture date nowhere near the server's now, which is what tells the two clocks apart.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
    Http::preventStrayRequests();
});

afterEach(function () {
    Checkpoint::observe(null);
    DB::purge('pay_probe');
    Http::assertNothingSent();
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * A line of the customer's messages, a key of customer-mail.php under its file, in English, the
 * business filled in.
 */
function approvalLine(string $key): string
{
    return customerMailLine('customer-mail.'.$key, ['business' => approvalTenant()->name]);
}

function approvalTenant(): Tenant
{
    $tenant = Tenant::query()->where('slug', 'luna')->first();
    assert($tenant instanceof Tenant);

    return $tenant;
}

/**
 * A request lodged with the owner the way the booking page lodges one: a hold of a service that
 * asks for approval lands awaiting_approval.
 *
 * @return array{uuid: string, manage_token: string}
 */
function approvalRequest(TestCase $test, string $service = 'Bridal trial', string $start = FixtureDay::START): array
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $ids('services', $service), 'staff_id' => $ids('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'manage_token' => $held['manage_token']];
}

function approvalApprove(string $uuid): void
{
    app(BookingTransitionFactory::class)->approve(approvalTenant())->approve($uuid, Actor::user(7));
}

/**
 * The columns an approval writes, read raw, with the payment hold's length measured against the
 * server's own now() in seconds.
 *
 * @return array{status: string, hold_class: string|null, ttl_seconds: int|null, approved_by: int|null, blocks: bool}
 */
function approvalRow(string $uuid): array
{
    $row = (array) DB::selectOne(
        'select b.status, b.hold_class, round(extract(epoch from b.hold_expires_at - now()))::int as ttl_seconds, b.approved_by,'
        .' bool_and(i.blocks) as blocks from tenant_luna.bookings b join tenant_luna.booking_items i on i.booking_id = b.id'
        .' where b.uuid = ? group by b.id',
        [$uuid],
    );

    return [
        'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
        'hold_class' => is_string($row['hold_class'] ?? null) ? $row['hold_class'] : null,
        'ttl_seconds' => is_int($row['ttl_seconds'] ?? null) ? $row['ttl_seconds'] : null,
        'approved_by' => is_int($row['approved_by'] ?? null) ? $row['approved_by'] : null,
        'blocks' => ($row['blocks'] ?? false) === true,
    ];
}

it('lands an approved online request on awaiting_payment under a working connection, with the payment hold class and an expiry the database wrote as its own now() plus the settings row\'s payment hold, and asks the provider nothing', function () {
    connectPaymentFake();
    DB::table('tenant_luna.settings')->update(['payment_ttl_hours' => 30]);
    $request = approvalRequest($this);

    approvalApprove($request['uuid']);

    $row = approvalRow($request['uuid']);

    expect($row)->toMatchArray(['status' => 'awaiting_payment', 'hold_class' => 'payment', 'approved_by' => 7, 'blocks' => true])
        ->and($row['ttl_seconds'])->toBeGreaterThan(30 * 3600 - 60)->toBeLessThanOrEqual(30 * 3600)
        ->and(paymentFake()->asked)->toBe([]);
});

it('lands an approval confirmed as before where nothing can take the money: an online request under no connection or a pending one, and a pay-on-site request under a working one', function (?ConnectionState $state, string $mode) {
    if ($state !== null) {
        connectPaymentFake($state);
    }

    DB::table('tenant_luna.services')->where('name', 'Bridal trial')->update(['payment_mode' => $mode]);
    $request = approvalRequest($this);

    approvalApprove($request['uuid']);

    expect(approvalRow($request['uuid']))->toMatchArray(['status' => 'confirmed', 'hold_class' => null, 'ttl_seconds' => null, 'approved_by' => 7, 'blocks' => true]);
})->with([
    'online, no connection' => [null, 'online'],
    'online, connection pending' => [ConnectionState::Pending, 'online'],
    'onsite, connection working' => [ConnectionState::Active, 'onsite'],
]);

it('lands a request the sweeper grants on timeout by the same rule, and expires the payment hold it leaves once that lapses unpaid, freeing the slot rather than granting it again', function () {
    connectPaymentFake();
    $request = approvalRequest($this);
    DB::update("update tenant_luna.bookings set on_approval_timeout = 'auto_approve', hold_expires_at = now() - interval '1 minute' where uuid = ?", [$request['uuid']]);

    app(ExpireHoldsFactory::class)->for(approvalTenant())->sweep();

    $granted = approvalRow($request['uuid']);

    DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?", [$request['uuid']]);
    app(ExpireHoldsFactory::class)->for(approvalTenant())->sweep();

    expect($granted)->toMatchArray(['status' => 'awaiting_payment', 'hold_class' => 'payment', 'approved_by' => null, 'blocks' => true])
        ->and($granted['ttl_seconds'])->toBeGreaterThan(24 * 3600 - 60)->toBeLessThanOrEqual(24 * 3600)
        ->and(approvalRow($request['uuid']))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'ttl_seconds' => null, 'blocks' => false])
        ->and(paymentFake()->asked)->toBe([]);
});

/**
 * The payment link the approved message carried, read out of the one message sent to the
 * customer: the one link in it.
 */
function payLinkMailed(): string
{
    $links = array_values(array_filter(seamLinksIn(seamTextAt('casey@example.com')), fn (string $link): bool => str_contains($link, '/link?')));

    expect($links)->toHaveCount(1);

    return $links[0];
}

/**
 * An online request approved into awaiting_payment under the fake, with the approved message's
 * link read out of the mail it went in.
 *
 * @return array{uuid: string, manage_token: string, link: string}
 */
function payLinkApproved(TestCase $test): array
{
    connectPaymentFake();
    $request = approvalRequest($test);
    seamTransport()->flush();

    approvalApprove($request['uuid']);

    return [...$request, 'link' => payLinkMailed()];
}

/**
 * The payment columns of a booking, raw.
 *
 * @return array{status: string, payment_ref: string|null, hold_expires_at: string|null, sealed: bool}
 */
function payLinkRow(string $uuid): array
{
    $row = (array) DB::selectOne(
        "select status, payment_ref, to_char(hold_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') as hold_expires_at,"
        .' manage_token_sealed is not null as sealed from tenant_luna.bookings where uuid = ?',
        [$uuid],
    );

    return [
        'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
        'payment_ref' => is_string($row['payment_ref'] ?? null) ? $row['payment_ref'] : null,
        'hold_expires_at' => is_string($row['hold_expires_at'] ?? null) ? $row['hold_expires_at'] : null,
        'sealed' => ($row['sealed'] ?? false) === true,
    ];
}

/**
 * The act the status page's button makes: a POST to the link it was opened from.
 *
 * @return TestResponse<Response>
 */
function payLinkAct(TestCase $test, string $link): TestResponse
{
    return $test->post($link);
}

it('sends the approved message for an awaiting_payment landing under the approved key, the one switch left on, with the signed payment link and no calendar file, and carries the link with the acknowledgement switched off', function (bool $acknowledged) {
    // Every switch off but the approved key's, and the acknowledgement's where it is on: a
    // message under any other key is dropped at the seam, so the one going out is under this.
    seamSwitchOff($acknowledged ? seamEveryKeyBut(MessageKey::Approved, MessageKey::Acknowledged) : seamEveryKeyBut(MessageKey::Approved));

    $approved = payLinkApproved($this);
    $mail = seamAt('casey@example.com');
    $text = (string) $mail->getTextBody();

    expect($mail->getAttachments())->toBe([])
        ->and($mail->getSubject())->toBe(approvalLine('approved.awaiting_payment.subject'))
        ->and($text)->toContain(approvalLine('approved.awaiting_payment.approved'))
        ->and($text)->not->toContain(approvalLine('approved.confirmed.where_link.acknowledged'))
        ->and($text)->not->toContain(approvalLine('approved.confirmed.where_link.unacknowledged'))
        // Under the prefix of the booking's language, English here.
        ->and(str_starts_with($approved['link'], tenantUrl('luna', '/en/pay/'.$approved['uuid'].'/link?expires=')))->toBeTrue()
        ->and($approved['link'])->toContain('&signature=')
        ->and($approved['link'])->not->toContain($approved['manage_token'])
        ->and(paymentFake()->asked)->toBe([]);
})->with(['acknowledgement on' => true, 'acknowledgement off' => false]);

it('sends the approved message with its payment link for an awaiting_payment landing whatever emails_off holds, since the key is held on while a working connection exists', function () {
    seamSwitchOff([MessageKey::Approved->value]);
    connectPaymentFake();
    $request = approvalRequest($this);
    seamTransport()->flush();

    approvalApprove($request['uuid']);

    expect((string) seamAt('casey@example.com')->getTextBody())->toContain(approvalLine('approved.awaiting_payment.approved'))
        ->and(approvalRow($request['uuid'])['status'])->toBe('awaiting_payment')
        ->and(paymentFake()->asked)->toBe([]);
});

it('opens the link on a status page that reads the booking awaiting its payment and offers the button posting to the same link, writing nothing and asking no provider however often it is fetched', function () {
    $approved = payLinkApproved($this);
    $before = payLinkRow($approved['uuid']);

    foreach ([1, 2] as $fetch) {
        $this->get($approved['link'])->assertOk()
            ->assertSee(pageSentence('pages.pay.waiting.title'))
            ->assertSee(pageSentence(PayPages::JUST_PAID))
            ->assertSee('action="'.e($approved['link']).'"', false)
            ->assertDontSee('casey@example.com')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    expect(payLinkRow($approved['uuid']))->toBe($before)
        ->and($before['payment_ref'])->toBeNull()
        ->and(paymentFake()->asked)->toBe([]);
});

it('takes the act on a live payment hold on to the provider, writing payment_ref and the session\'s end as the hold\'s in one write and sealing nothing, and the provider brings the customer back to the status page under the same signature whatever it adds', function () {
    $approved = payLinkApproved($this);

    $response = payLinkAct($this, $approved['link']);
    $session = paymentFake()->sessions['pd_1'] ?? null;

    assert($session !== null);

    $response->assertStatus(303)
        ->assertRedirect($approved['link'].'&paydesk_session=pd_1')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $after = payLinkRow($approved['uuid']);

    expect($after)->toMatchArray(['status' => 'awaiting_payment', 'payment_ref' => 'pd_1', 'sealed' => false])
        ->and($after['hold_expires_at'])->toBe($session['endsAt']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'))
        ->and($session['returnUrl'])->toBe($approved['link'])
        ->and(paymentFake()->transactionOpen)->toBe([false]);

    $this->get($approved['link'].'&paydesk_session=pd_1&anything=else')->assertOk()->assertSee(pageSentence('pages.pay.waiting.title'));
    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link'].'&paydesk_session=pd_1');

    expect(payLinkRow($approved['uuid']))->toBe($after)
        ->and(paymentFake()->created())->toBe(1);
});

it('answers the act pressed again after the customer paid and before the payment\'s notification landed by sending the customer back to the status page, which says once that the payment is on its way, never the 502 page, writing nothing', function () {
    $approved = payLinkApproved($this);
    payLinkAct($this, $approved['link'])->assertStatus(303);
    $paying = payLinkRow($approved['uuid']);
    paymentFake()->completed[] = 'pd_1';

    payLinkAct($this, $approved['link'])->assertStatus(303)
        ->assertRedirect($approved['link'])
        ->assertSessionHas(CustomerNotice::KEY, ['kind' => 'info', 'message' => PayHopAnswer::PAYMENT_ON_ITS_WAY]);

    $this->get($approved['link'])->assertOk()->assertSee(pageSentence(PayHopAnswer::PAYMENT_ON_ITS_WAY))->assertSee(pageSentence('pages.pay.waiting.title'));
    $this->get($approved['link'])->assertOk()->assertDontSee(pageSentence(PayHopAnswer::PAYMENT_ON_ITS_WAY));

    expect(payLinkRow($approved['uuid']))->toBe($paying)
        ->and(paymentFake()->created())->toBe(1);
});

it('shortens a payment hold longer than the provider\'s session to the session\'s end at the first visit, and the link stays good exactly as long as the hold as re-read', function () {
    DB::table('tenant_luna.settings')->update(['payment_ttl_hours' => 30]);
    $approved = payLinkApproved($this);
    $before = payLinkRow($approved['uuid']);
    paymentFake()->sessionOverrunSeconds = -6 * 3600;

    payLinkAct($this, $approved['link'])->assertStatus(303);

    $after = payLinkRow($approved['uuid']);
    $lost = (new DateTimeImmutable((string) $before['hold_expires_at'], new DateTimeZone('UTC')))->getTimestamp()
        - (new DateTimeImmutable((string) $after['hold_expires_at'], new DateTimeZone('UTC')))->getTimestamp();

    expect($lost)->toBe(6 * 3600);

    DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 second' where uuid = ?", [$approved['uuid']]);

    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.ended.title'))->assertDontSee(pageSentence('pages.pay.pay_now'));
    payLinkAct($this, $approved['link'])->assertStatus(409)->assertSee(pageSentence('pages.pay.ended.title'));

    expect(paymentFake()->created())->toBe(1);
});

it('answers the act on a payment hold that lapsed or was cancelled with the worded page and asks the provider nothing, and the status page reads it no longer held', function (string $ending) {
    $approved = payLinkApproved($this);

    match ($ending) {
        'lapsed' => DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?", [$approved['uuid']]),
        'swept' => (function () use ($approved): void {
            DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?", [$approved['uuid']]);
            app(ExpireHoldsFactory::class)->for(approvalTenant())->sweep();
        })(),
        'cancelled' => app(BookingTransitionFactory::class)->cancel(approvalTenant())->cancel($approved['uuid'], Actor::user(7)),
        default => throw new LogicException($ending),
    };

    $before = payLinkRow($approved['uuid']);

    payLinkAct($this, $approved['link'])->assertStatus(409)->assertSee(pageSentence('pages.pay.ended.title'));
    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.ended.title'))->assertDontSee(pageSentence('pages.pay.pay_now'));

    expect(paymentFake()->asked)->toBe([])
        ->and(payLinkRow($approved['uuid']))->toBe($before);
})->with(['lapsed', 'swept', 'cancelled']);

it('refuses a tampered link and one past its outer life, thirty days after the booking\'s last segment, with one 403 naming nothing, on the page and the act alike, and admits it the moment before whatever the signature\'s age', function () {
    $approved = payLinkApproved($this);
    $tampered = [
        'signature' => preg_replace('/signature=([0-9a-f])/', 'signature=0$1', $approved['link']),
        'expiry' => preg_replace('/expires=([0-9]+)/', 'expires=9$1', $approved['link']),
        'uuid' => str_replace($approved['uuid'], '00000000-0000-4000-8000-000000000000', $approved['link']),
        'no expiry' => preg_replace('/expires=[0-9]+&/', '', $approved['link']),
    ];

    foreach ($tampered as $link) {
        $this->get((string) $link)->assertForbidden()->assertSee(pageSentence('pages.pay.refused.title'))->assertDontSee('Bridal');
        payLinkAct($this, (string) $link)->assertForbidden()->assertSee(pageSentence('pages.pay.refused.title'));
    }

    $life = PayLinks::outerLife(new DateTimeImmutable(FixtureDay::DATE.' 12:00', new DateTimeZone(FixtureDay::ZONE)));
    preg_match('/expires=([0-9]+)/', $approved['link'], $expires);

    expect((int) ($expires[1] ?? 0))->toBe($life->getTimestamp());

    app()->instance(Clock::class, FixedClock::at($life->modify('-1 second')->format('Y-m-d H:i:s')));
    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.waiting.title'));

    app()->instance(Clock::class, FixedClock::at($life->format('Y-m-d H:i:s')));
    $this->get($approved['link'])->assertForbidden()->assertSee(pageSentence('pages.pay.refused.title'));
    payLinkAct($this, $approved['link'])->assertForbidden();

    expect(paymentFake()->asked)->toBe([])
        ->and(payLinkRow($approved['uuid'])['payment_ref'])->toBeNull();
});

it('refuses a link past its outer life with a later expiry appended under any spelling of the name, on the page and the act alike, since the instant read against the clock is the one the signature covers, and admits a live link with the provider\'s parameters appended', function (string $name) {
    $approved = payLinkApproved($this);
    $life = PayLinks::outerLife(new DateTimeImmutable(FixtureDay::DATE.' 12:00', new DateTimeZone(FixtureDay::ZONE)));
    $appended = $approved['link'].'&'.$name.'=99999999999';

    app()->instance(Clock::class, FixedClock::at($life->modify('-1 second')->format('Y-m-d H:i:s')));
    $this->get($approved['link'].'&paydesk_session=pd_9&anything=else')->assertOk()->assertSee(pageSentence('pages.pay.waiting.title'));

    app()->instance(Clock::class, FixedClock::at($life->format('Y-m-d H:i:s')));
    $this->get($appended)->assertForbidden()->assertSee(pageSentence('pages.pay.refused.title'))->assertDontSee('Bridal');
    payLinkAct($this, $appended)->assertForbidden()->assertSee(pageSentence('pages.pay.refused.title'));

    expect(paymentFake()->asked)->toBe([])
        ->and(payLinkRow($approved['uuid'])['payment_ref'])->toBeNull();
})->with(['%65xpires', 'expire%73', '%65%78%70%69%72%65%73', '+expires', '%20expires']);

it('opens the link\'s page and takes its act while the subscription is lapsed, canceled or incomplete: paying is a guest route and the signed road carries no subscription gate', function (string $state) {
    $approved = payLinkApproved($this);
    billingSet(['subscription_state' => $state]);

    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.waiting.title'));
    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link'].'&paydesk_session=pd_1');

    expect(payLinkRow($approved['uuid'])['payment_ref'])->toBe('pd_1');
})->with(['past_due', 'canceled', 'incomplete']);

it('settles a live payment hold on site through the shared guarded write where no provider works at the act, lands the customer on the status page reading confirmed, and a second visit finds it confirmed', function () {
    $approved = payLinkApproved($this);
    connectPaymentFake(ConnectionState::Disabled);

    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link']);

    expect(payLinkRow($approved['uuid']))->toMatchArray(['status' => 'confirmed', 'payment_ref' => null, 'hold_expires_at' => null, 'sealed' => false])
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1);

    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.confirmed'))->assertDontSee(pageSentence('pages.pay.pay_now'));
    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link']);

    expect(payLinkRow($approved['uuid'])['status'])->toBe('confirmed')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1)
        ->and(paymentFake()->asked)->toBe([]);
});

it('settles on site at a second press where the connection stopped working after the first opened a session, keeping payment_ref as recorded and asking no provider, lands the customer on the status page reading confirmed, and a third visit finds it confirmed', function (Closure $stop) {
    $approved = payLinkApproved($this);
    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link'].'&paydesk_session=pd_1');
    $asked = paymentFake()->asked;
    $stop();

    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link']);

    expect(payLinkRow($approved['uuid']))->toMatchArray(['status' => 'confirmed', 'payment_ref' => 'pd_1', 'hold_expires_at' => null, 'sealed' => false])
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1)
        ->and(paymentFake()->asked)->toBe($asked);

    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.confirmed'))->assertDontSee(pageSentence('pages.pay.pay_now'));
    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link']);

    expect(payLinkRow($approved['uuid'])['status'])->toBe('confirmed')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1)
        ->and(paymentFake()->asked)->toBe($asked)
        ->and(paymentFake()->created())->toBe(1);
})->with([
    'the row disabled' => [fn () => connectPaymentFake(ConnectionState::Disabled)],
    'its check failed' => [fn () => connectPaymentFake(ConnectionState::Active, false)],
]);

/**
 * A concurrent reschedule of the booking named, at the checkpoint between the staff-day locks
 * and the booking row, on a connection of its own so it commits under the write: the one thing
 * that takes a booking's items off the staff-days a transition locked. Made at every attempt,
 * the write answers busy.
 */
function payLinkMovedUnderTheLock(string $uuid, int &$moves): void
{
    Checkpoint::observe(function (string $name) use ($uuid, &$moves): void {
        if ($name !== GuardedWrite::AFTER_LOCK) {
            return;
        }

        $moves++;

        LunaFixture::probe('pay_probe')->update(
            "update tenant_luna.booking_items set start_utc = start_utc + interval '1 day',"
            ." end_utc = end_utc + interval '1 day', block_start_utc = block_start_utc + interval '1 day',"
            ." block_end_utc = block_end_utc + interval '1 day', processing_ends_utc = processing_ends_utc + interval '1 day'"
            .' from tenant_luna.bookings b where b.id = booking_id and b.uuid = ?::uuid',
            [$uuid],
        );
    });
}

it('answers an on-site settlement that lost to a move under the lock as the decide links answer one, sending the customer back to the status page reading the booking as it stands rather than to the framework\'s error page', function () {
    $approved = payLinkApproved($this);
    connectPaymentFake(ConnectionState::Disabled);
    $moves = 0;
    payLinkMovedUnderTheLock($approved['uuid'], $moves);

    payLinkAct($this, $approved['link'])->assertStatus(303)->assertRedirect($approved['link']);
    Checkpoint::observe(null);

    expect($moves)->toBe(GuardedWrite::ATTEMPTS)
        ->and(payLinkRow($approved['uuid'])['status'])->toBe('awaiting_payment')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0)
        ->and(paymentFake()->asked)->toBe([]);

    $this->get($approved['link'])->assertOk()->assertSee(pageSentence('pages.pay.waiting.title'));
});

/**
 * The payment's notification for a booking, posted to the central endpoint as the fake's
 * provider posts it, sealed with luna's signing phrase.
 *
 * @return TestResponse<Response>
 */
function approvalPaid(TestCase $test, string $uuid, string $event = 'evt_1'): TestResponse
{
    $body = json_encode(['id' => $event, 'type' => FakePaymentAdapter::SETTLED, 'booking' => $uuid, 'payment' => 'pd_1'], JSON_THROW_ON_ERROR);

    return $test->call('POST', centralUrl('/webhooks/'.FakePaymentAdapter::SLUG.'/hook-token-luna'), [], [], [], [
        'HTTP_PAYDESK_SEAL' => FakePaymentAdapter::seal($body, paymentFakeCredentials()['signing_phrase']),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

/**
 * The one message whose subject ends as given, picked from what the suite's sync queue has sent
 * since the last flush, and held to be the customer's: the confirmation, its whole subject read
 * through the translator, which schedules the reminder the sync queue runs at once, and the
 * move's, its whole subject read through the translator too, each picked by its subject from
 * beside the business's own notices.
 */
function approvalMail(string $subjectEnd): Email
{
    $sent = array_values(array_filter(seamSent(), fn (Email $message): bool => str_ends_with((string) $message->getSubject(), $subjectEnd)));

    expect($sent)->toHaveCount(1)
        ->and(array_map(fn ($to): string => $to->getAddress(), $sent[0]->getTo()))->toBe(['casey@example.com']);

    return $sent[0];
}

it('confirms an approved booking when its payment lands, and the confirmation carries the calendar file the approval did not, numbered by the reader above zero', function () {
    $approved = payLinkApproved($this);
    $approval = seamAt('casey@example.com');

    payLinkAct($this, $approved['link'])->assertStatus(303);
    seamTransport()->flush();

    approvalPaid($this, $approved['uuid'])->assertOk();

    $confirmation = approvalMail(approvalLine('confirmed.subject'));
    $files = $confirmation->getAttachments();
    $revision = app(BookingReader::class)->revision(approvalTenant(), $approved['uuid']);

    expect($approval->getAttachments())->toBe([])
        ->and(payLinkRow($approved['uuid'])['status'])->toBe('confirmed')
        ->and($revision)->toBeGreaterThan(0)
        ->and($files)->toHaveCount(1)
        ->and($files[0]->getBody())->toContain(icsSequenceLine($revision));
});

it('tells the owner a request the clock granted into awaiting_payment awaits its payment and the customer how to pay, and tells the customer when the payment hold lapses unpaid', function () {
    connectPaymentFake();
    $request = approvalRequest($this);
    DB::update("update tenant_luna.bookings set on_approval_timeout = 'auto_approve', hold_expires_at = now() - interval '1 minute' where uuid = ?", [$request['uuid']]);
    seamTransport()->flush();

    app(ExpireHoldsFactory::class)->for(approvalTenant())->sweep();

    $owner = seamTextAt('owner@luna.example');
    $customer = seamTextAt('casey@example.com');
    $link = payLinkMailed();

    seamTransport()->flush();
    DB::update("update tenant_luna.bookings set hold_expires_at = now() - interval '1 minute' where uuid = ?", [$request['uuid']]);
    app(ExpireHoldsFactory::class)->for(approvalTenant())->sweep();

    $lapse = seamAt('casey@example.com');

    expect($owner)->toContain(businessMailLine('business-mail.auto_approved.awaiting_payment.told'))
        ->and($owner)->not->toContain(businessMailLine('business-mail.auto_approved.confirmed.told'))
        ->and($owner)->not->toContain(businessMailLine('business-mail.auto_approved.confirmed.untold', ['label' => adminLine(MessageKey::Approved->labelKey()), 'business' => 'Luna Hair Studio']))
        ->and($link)->toContain('/pay/'.$request['uuid'].'/link?')
        ->and($customer)->toContain(approvalLine('approved.awaiting_payment.approved'))
        ->and($customer)->not->toContain(approvalLine('approved.confirmed.where_link.acknowledged'))
        ->and(seamAddresses())->toBe(['casey@example.com'])
        ->and($lapse->getSubject())->toBe(approvalLine('request_expired.unpaid.subject'))
        ->and((string) $lapse->getTextBody())->toContain(approvalLine('request_expired.unpaid.expired'))
        ->and($lapse->getAttachments())->toBe([]);
});

it('expires the session the token hop opened when the booking awaiting its payment moves, and the signed link\'s act opens the next for the booking\'s own total, which the move left as it was, without dropping the seal the token hop left, so the confirmation after the payment still carries the manage link', function () {
    $approved = payLinkApproved($this);

    $this->get(tenantUrl('luna', '/pay/'.$approved['uuid'].'?'.http_build_query(['token' => $approved['manage_token']])))->assertStatus(303);
    app(BookingTransitionFactory::class)->reschedule(approvalTenant())->reschedule($approved['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 15:00'));
    $moved = payLinkRow($approved['uuid']);

    payLinkAct($this, $approved['link'])->assertStatus(303);
    $reopened = payLinkRow($approved['uuid']);
    seamTransport()->flush();
    approvalPaid($this, $approved['uuid'])->assertOk();

    expect($moved)->toMatchArray(['status' => 'awaiting_payment', 'payment_ref' => null, 'sealed' => true])
        ->and(paymentFake()->expired)->toBe(['pd_1'])
        ->and($reopened)->toMatchArray(['status' => 'awaiting_payment', 'payment_ref' => 'pd_2', 'sealed' => true])
        ->and(paymentFake()->sessions['pd_2']['total']->minor ?? null)->toBe(DB::table('tenant_luna.bookings')->where('uuid', $approved['uuid'])->value('total_minor'))
        ->and(payLinkRow($approved['uuid'])['status'])->toBe('confirmed')
        ->and((string) approvalMail(approvalLine('confirmed.subject'))->getTextBody())->toContain(app(TenantUrls::class)->manageLink(Slug::of('luna'), $approved['uuid'], $approved['manage_token'], SiteLanguage::English));
});

it('reports a committed approval to the admin as a success whatever the provider would answer, because the approval path asks the provider nothing', function () {
    connectPaymentFake();
    paymentFake()->failWith = 'The provider is down.';
    $request = approvalRequest($this);
    $owner = User::query()->where('email', 'owner@luna.example')->sole();
    $dialog = tenantUrl('luna', '/admin/bookings/'.$request['uuid']);

    $this->actingAs($owner)->from($dialog)->post(tenantUrl('luna', '/admin/bookings/'.$request['uuid'].'/approve'))->assertRedirect($dialog);

    expect(session('flash'))->toBe(['kind' => 'success', 'message' => __(BookingActionController::APPROVED)])
        ->and(approvalRow($request['uuid'])['status'])->toBe('awaiting_payment')
        ->and(paymentFake()->asked)->toBe([])
        ->and(paymentFake()->sessions)->toBe([]);
});

it('keeps the manage link the token hop sealed through a move of the booking awaiting its payment, so the confirmation after the payment carries it, and tells the customer of that move with no calendar file and that the booking still awaits its payment, the confirmation\'s file being the first and a later move\'s carrying its own', function () {
    $approved = payLinkApproved($this);

    $this->get(tenantUrl('luna', '/pay/'.$approved['uuid'].'?'.http_build_query(['token' => $approved['manage_token']])))->assertStatus(303);
    $sealed = payLinkRow($approved['uuid'])['sealed'];

    app(BookingTransitionFactory::class)->reschedule(approvalTenant())->reschedule($approved['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 15:00'));
    $moved = payLinkRow($approved['uuid']);
    $waitingMove = approvalMail(approvalLine('moved.booking.subject'));
    seamTransport()->flush();

    approvalPaid($this, $approved['uuid'])->assertOk();

    // Under the prefix of the booking's language, English here.
    $link = app(TenantUrls::class)->manageLink(Slug::of('luna'), $approved['uuid'], $approved['manage_token'], SiteLanguage::English);
    $confirmation = approvalMail(approvalLine('confirmed.subject'));
    $confirmed = app(BookingReader::class)->revision(approvalTenant(), $approved['uuid']);
    seamTransport()->flush();

    app(BookingTransitionFactory::class)->reschedule(approvalTenant())->reschedule($approved['uuid'], Actor::customer(), LunaFixture::at('2026-11-03 16:00'));
    $confirmedMove = approvalMail(approvalLine('moved.booking.subject'));
    $later = app(BookingReader::class)->revision(approvalTenant(), $approved['uuid']);

    expect($sealed)->toBeTrue()
        ->and($moved)->toMatchArray(['status' => 'awaiting_payment', 'sealed' => true])
        ->and($waitingMove->getAttachments())->toBe([])
        ->and((string) $waitingMove->getTextBody())->toContain(approvalLine('moved.awaiting_payment'))
        ->and((string) $waitingMove->getTextBody())->not->toContain(approvalLine('moved.calendar'))
        ->and(payLinkRow($approved['uuid'])['status'])->toBe('confirmed')
        ->and((string) $confirmation->getTextBody())->toContain($link)
        ->and($confirmation->getAttachments())->toHaveCount(1)
        ->and($confirmation->getAttachments()[0]->getBody())->toContain(icsSequenceLine($confirmed))
        ->and($confirmedMove->getAttachments())->toHaveCount(1)
        ->and($later)->toBeGreaterThan($confirmed)
        ->and($confirmedMove->getAttachments()[0]->getBody())->toContain(icsSequenceLine($later));
});

/**
 * Bridal trial on Elena over the fixture day as the availability endpoint answers it, through
 * whichever mask cache the container holds at the time.
 */
function approvalAvailability(TestCase $test): string
{
    $ids = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $query = http_build_query([
        'items' => (string) json_encode([['service_id' => $ids('services', 'Bridal trial'), 'staff_id' => $ids('staff', 'Elena Papadaki')]]),
        'from' => FixtureDay::DATE,
        'to' => (new DateTimeImmutable(FixtureDay::DATE, new DateTimeZone(FixtureDay::ZONE)))->modify('+1 day')->format('Y-m-d'),
    ]);

    return (string) $test->getJson(tenantUrl('luna', '/api/v1/availability?'.$query))->assertOk()->getContent();
}

/**
 * Binds a mask cache over a fresh array store and hands it back: every read after this is warm
 * once it has been made once.
 */
function approvalWarmCache(): StaffDayMaskCache
{
    $cache = new StaffDayMaskCache(new CacheRepository(new ArrayStore));
    app()->instance(StaffDayMaskCache::class, $cache);

    return $cache;
}

/**
 * The same availability read twice, through the warm cache handed in and then through the null
 * store, which is the cold base every warm answer is held to.
 *
 * @return array{string, string} the warm answer and the cold one
 */
function approvalWarmAndCold(TestCase $test, StaffDayMaskCache $warm): array
{
    app()->instance(StaffDayMaskCache::class, $warm);
    $warmRead = approvalAvailability($test);
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    $coldRead = approvalAvailability($test);
    app()->instance(StaffDayMaskCache::class, $warm);

    return [$warmRead, $coldRead];
}

it('keeps a warm availability cache and a cold one agreeing about a payment hold the act shortened, because the act writes the hold\'s end inside the write protocol, bumping the staff-days the booking touches before it touches the booking', function () {
    $approved = payLinkApproved($this);
    $warm = approvalWarmCache();
    $held = approvalAvailability($this);
    [$warmHeld, $coldHeld] = approvalWarmAndCold($this, $warm);

    // The session ends a minute before the approval wrote the hold, so the read after the act
    // stands where a shortened hold has ended by its new end and not by its old one: the
    // instant at which an entry built before the act and the rows would differ.
    paymentFake()->sessionOverrunSeconds = -(24 * 3600 + 60);
    payLinkAct($this, $approved['link'])->assertStatus(303);

    [$warmRead, $coldRead] = approvalWarmAndCold($this, $warm);

    expect($warmHeld)->toBe($held)
        ->and($coldHeld)->toBe($held)
        ->and(payLinkRow($approved['uuid']))->toMatchArray(['status' => 'awaiting_payment', 'payment_ref' => 'pd_1'])
        ->and($coldRead)->not->toBe($held)
        ->and($warmRead)->toBe($coldRead);
});

/**
 * Whether the cache serves an entry for Elena's fixture day, under the revision her staff-day
 * holds now, to a read standing at the instant given: the question the availability read asks
 * it, with the clock handed in rather than the database's. The store keeps its lifetimes by the
 * framework's clock, so that clock stands at the same instant while it is asked: an entry the
 * store would have evicted by then is evicted, and one it would still hold is still held.
 */
function approvalEntryServedAt(StaffDayMaskCache $cache, DateTimeImmutable $at): bool
{
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    $revision = DB::table('tenant_luna.staff_days')->where('staff_id', $elena)->where('day', FixtureDay::DATE)->value('rev');
    assert(is_int($elena));

    $key = StaffDayMaskCache::key('tenant_luna', $elena, FixtureDay::DATE, Granularity::minutes(5), is_int($revision) ? $revision : null);
    $before = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::instance($at));

    try {
        return $cache->fetch([$key => 288], $at) !== [];
    } finally {
        Carbon::setTestNow($before);
    }
}

it('keeps a warm availability cache and a cold one agreeing about a request the clock granted into a payment hold, because an entry built while the request waited lives no longer than its window, so none outlives the window into the payment hold the grant writes with no revision bumped', function () {
    connectPaymentFake();
    // The settings' floor: a payment hold of one hour, inside the cache's one-day life, so at the
    // hold's end the store would still hold an entry built while the request waited, had that
    // entry been stored for a day.
    DB::table('tenant_luna.settings')->update(['payment_ttl_hours' => 1]);
    $request = approvalRequest($this);
    DB::update("update tenant_luna.bookings set on_approval_timeout = 'auto_approve', hold_expires_at = now() + interval '1 second' where uuid = ?", [$request['uuid']]);

    $warm = approvalWarmCache();
    $waiting = approvalAvailability($this);
    [$warmWaiting] = approvalWarmAndCold($this, $warm);

    // Wait the window out by the database's clock, the one the sweeper and the reader share.
    $remaining = DB::scalar('select extract(epoch from (hold_expires_at - now())) from tenant_luna.bookings where uuid = ?', [$request['uuid']]);
    assert(is_float($remaining) || is_int($remaining) || is_string($remaining));
    usleep((int) (max(0.0, (float) $remaining) * 1_000_000) + 100_000);

    app(ExpireHoldsFactory::class)->for(approvalTenant())->sweep();

    // How far past the grant the payment hold ends, by the database's clock: the settings' one
    // hour, so a hold written for the default day, or a grant that never read the setting, fails.
    $granted = DB::scalar('select extract(epoch from (hold_expires_at - now())) from tenant_luna.bookings where uuid = ?', [$request['uuid']]);
    assert(is_float($granted) || is_int($granted) || is_string($granted));
    $granted = (float) $granted;

    [$warmRead, $coldRead] = approvalWarmAndCold($this, $warm);
    $ends = DB::scalar("select to_char(hold_expires_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') from tenant_luna.bookings where uuid = ?", [$request['uuid']]);
    assert(is_string($ends));
    $ends = new DateTimeImmutable($ends, new DateTimeZone('UTC'));

    // The payment hold lapses an hour from now, past what a test can wait for; the cache is
    // asked at that instant directly, the store's own clock standing there too. The rows have
    // freed the slot by then, so a read standing there must find no entry and build cold; an
    // entry built while the request waited and kept for the store's one-day life would still be
    // held, counting the slot busy. The moment before, the entry the read after the grant stored
    // is still served, and it counts the slot busy as the rows do.
    expect($warmWaiting)->toBe($waiting)
        ->and(approvalRow($request['uuid'])['status'])->toBe('awaiting_payment')
        ->and($granted)->toBeGreaterThan(3600 - 60)->toBeLessThanOrEqual(3600)
        ->and($granted)->toBeLessThan(StaffDayMaskCache::LIFETIME_SECONDS)
        ->and($warmRead)->toBe($coldRead)
        ->and(approvalEntryServedAt($warm, $ends->modify('-1 second')))->toBeTrue()
        ->and(approvalEntryServedAt($warm, $ends))->toBeFalse();
});

it('answers an act on the signed link whose write met the staff-day held past the lock timeout as a lost race: the row re-read, the unopened page\'s words answered at 503 with a Retry-After, nothing written, the runner\'s line the one log record, and the act again opens the payment', function () {
    $approved = payLinkApproved($this);
    $before = payLinkRow($approved['uuid']);
    $log = runnerLog();
    $probe = bookingStaffDaysHeld('pay_probe', $approved['uuid']);

    try {
        $busy = payLinkAct($this, $approved['link']);
    } finally {
        $probe->rollBack();
    }

    // Lock contention, the house's 503 busy, not the 502 a provider's failure answers.
    $busy->assertStatus(503)->assertHeader('Retry-After', '1')->assertSee(pageSentence('pages.pay.unopened.title'))->assertSee(pageSentence('pages.pay.unopened.again'));
    $lines = $log->getRecords();

    // The session the act opened is orphaned, as a lapsed hold orphans one; the payment hold
    // and the reference stand as they were, and the signed road seals nothing either way. The
    // runner's Busy line is the request's one log record: nothing reports it again.
    expect(payLinkRow($approved['uuid']))->toBe($before)
        ->and($before)->toMatchArray(['status' => 'awaiting_payment', 'payment_ref' => null, 'sealed' => false])
        ->and(paymentFake()->created())->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]->context['failure'] ?? null)->toBe(Busy::class)
        ->and($lines[0]->context['sqlstate'] ?? null)->toBe('55P03');

    payLinkAct($this, $approved['link'])->assertStatus(303);

    expect(payLinkRow($approved['uuid'])['payment_ref'])->not->toBeNull()
        ->and(paymentFake()->created())->toBe(2);
});
