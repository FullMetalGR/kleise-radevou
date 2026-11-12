<?php

use App\Domain\Booking\ManageToken;
use App\Domain\Customer\CustomerSecret;
use App\Http\ManageTokenGuard;
use App\Http\MyBookingsCookie;
use App\Providers\AppServiceProvider;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * The manage token's one guard and its second credential: where a request presents no token, a
 * live My bookings session on its cookie whose address is the booking's customer_email, compared
 * as citext compares, is the credential. Every miss is the one 404 a token's miss is; a request
 * carrying both is judged by the token; a request carrying neither is the malformed request it
 * always was. The derived expiry holds for both, the bookings limiter counts a session's calls as
 * a token's, and the actor of the trail stays the customer.
 *
 * A JSON request carries the cookie only after withCredentials(), as a browser's fetch carries it
 * only under credentials include; a page request carries it always, as a navigation does.
 *
 * Not wrapped in a transaction: the write routes enter the transaction runner, which refuses to
 * open inside another. luna is in Europe/Athens; the clock stands at 06:00 UTC on Monday
 * 2026-11-02, 26 hours before the fixture's start, outside every window.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    FixtureDay::pinClock();
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/** The address the walk's bookings are held under, as the hold spells it. */
const SG_CASEY = 'casey@example.com';

/**
 * A Haircut with Elena held over the wire at an Athens wall-clock time, the way the booking app
 * holds one: pending, pay on site, its manage token in the answer.
 *
 * @return array{uuid: string, token: string}
 */
function sgHold(TestCase $test, string $start, string $email = SG_CASEY): array
{
    $id = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => $email,
        'items' => [['service_id' => $id('services', 'Haircut'), 'staff_id' => $id('staff', 'Elena Papadaki')]],
        'start' => sgStart($start),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

/**
 * An Athens wall-clock time as the wire spells a start.
 */
function sgStart(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone(FixtureDay::ZONE)))->format(DateTimeImmutable::RFC3339);
}

/**
 * A My bookings session of luna's for an address, written as its row, and the plain secret its
 * cookie carries. Live for thirty days from the pinned clock unless the test says otherwise.
 */
function sgSession(string $email, string $expiresAt = '2026-12-02 06:00:00+00:00', ?string $revokedAt = null): string
{
    $secret = CustomerSecret::generate();

    DB::table('tenant_luna.customer_sessions')->insert([
        'email' => $email,
        'session_hash' => $secret->hash(),
        'created_at' => '2026-11-02 05:00:00+00:00',
        'expires_at' => $expiresAt,
        'revoked_at' => $revokedAt,
    ]);

    return $secret->plain;
}

/**
 * The token as a GET or a DELETE carries it, or nothing.
 *
 * @param  array<string, string>  $query
 */
function sgQuery(?string $token, array $query = []): string
{
    $spelled = http_build_query($token === null ? $query : [...$query, 'token' => $token]);

    return $spelled === '' ? '' : '?'.$spelled;
}

/**
 * What a refusal is, compared byte for byte: the status and the body.
 *
 * @param  TestResponse<Response>  $answer
 * @return array{int, string}
 */
function sgAnswer(TestResponse $answer): array
{
    return [$answer->getStatusCode(), (string) $answer->getContent()];
}

/**
 * Each booking's status, by uuid.
 *
 * @param  list<string>  $uuids
 * @return list<mixed>
 */
function sgStatuses(array $uuids): array
{
    return array_map(fn (string $uuid): mixed => DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'), $uuids);
}

/**
 * The trail one booking's rows carry, as the actor, the action and the payload.
 *
 * @return list<string>
 */
function sgTrail(string $uuid): array
{
    /** @var list<string> $trail */
    $trail = DB::table('tenant_luna.audit_log as a')
        ->join('tenant_luna.bookings as b', 'b.id', '=', 'a.booking_id')
        ->where('b.uuid', $uuid)
        ->orderBy('a.id')
        ->selectRaw("a.actor || ' ' || a.action || ' ' || a.payload::text as line")
        ->pluck('line')
        ->all();

    return $trail;
}

/**
 * A route's request from the walk's dataset, sent: given the booking, the token or none, and the
 * Athens time a move goes to.
 *
 * @return TestResponse<Response>
 */
function sgSend(Closure $send, TestCase $test, string $uuid, ?string $token, string $to): TestResponse
{
    $answer = $send($test, $uuid, $token, $to);

    assert($answer instanceof TestResponse);

    return $answer;
}

/**
 * A trail with its instants spelled as one word: two bookings held and moved a moment apart
 * differ in their instants and in nothing else, so this is what says the second credential wrote
 * nothing the token's road does not.
 *
 * @param  list<string>  $trail
 * @return list<string>
 */
function sgShape(array $trail): array
{
    return array_map(fn (string $line): string => (string) preg_replace('/"[0-9T:.+-]{19,}"/', '"at"', $line), $trail);
}

/*
 * The seven routes that take the token, routes/tenant.php: each with the request the booking app
 * or the browser makes, given the booking, the token or none, and the Athens time a move goes to;
 * and what the route answers once the guard has let it through, given where the pay hop is to
 * send the customer back to.
 */
dataset('the routes behind the guard', [
    'GET /pay/{uuid}' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->get(tenantUrl('luna', '/pay/'.$uuid.sgQuery($token))),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertStatus(303)->assertHeader('Location', $back)->assertHeader('Referrer-Policy', 'no-referrer');
        },
    ],
    'DELETE /api/v1/holds/{uuid}' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->deleteJson(tenantUrl('luna', '/api/v1/holds/'.$uuid.sgQuery($token))),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertNoContent();
            expect(sgStatuses([$uuid]))->toBe(['cancelled']);
        },
    ],
    'GET /api/v1/bookings/{uuid}' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.sgQuery($token))),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertOk()->assertJsonPath('uuid', $uuid)->assertJsonPath('customer_email', SG_CASEY);
        },
    ],
    'GET /api/v1/bookings/{uuid}/availability' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/availability'.sgQuery($token, ['from' => '2026-11-04', 'to' => '2026-11-05']))),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            expect($answer->json('2026-11-04'))->toContain('2026-11-04T13:00:00+00:00');
        },
    ],
    'POST /api/v1/bookings/{uuid}/confirm' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/confirm'), $token === null ? [] : ['token' => $token]),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertOk()->assertJsonPath('uuid', $uuid)->assertJsonPath('status', 'confirmed');
        },
    ],
    'POST /api/v1/bookings/{uuid}/cancel' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel'), $token === null ? [] : ['token' => $token]),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertOk()->assertJsonPath('uuid', $uuid)->assertJsonPath('status', 'cancelled');
        },
    ],
    'POST /api/v1/bookings/{uuid}/reschedule' => [
        fn (TestCase $test, string $uuid, ?string $token, string $to): TestResponse => $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), [...($token === null ? [] : ['token' => $token]), 'start' => sgStart($to)]),
        function (TestResponse $answer, string $uuid, string $back): void {
            $answer->assertOk()->assertJsonPath('uuid', $uuid)->assertJsonPath('status', 'pending');
            expect($answer->json('items.0.start'))->not->toBe(sgStart(FixtureDay::START));
        },
    ],
]);

it('lets the route through on a live session for the booking\'s address where no token is presented, answers every miss the one 404 a token\'s miss is, judges a request carrying both by the token, and writes nothing for the session', function (Closure $send, Closure $accepted) {
    $bySession = sgHold($this, FixtureDay::START);
    $byToken = sgHold($this, '2026-11-03 11:00');
    $theirs = sgHold($this, '2026-11-03 12:00', 'pat@example.test');
    // A booking of the address whose last segment ended more than the token's lifetime ago.
    $ended = mybBooking(SG_CASEY, '2026-09-01 08:00:00');

    // The session's address as somebody typed it into the link form: citext finds the bookings.
    $live = sgSession('Casey@Example.COM');
    $secrets = [
        'a cookie naming no session' => CustomerSecret::generate()->plain,
        'a session at its end' => sgSession(SG_CASEY, '2026-11-02 06:00:00+00:00'),
        'a revoked session' => sgSession(SG_CASEY, revokedAt: '2026-11-02 05:30:00+00:00'),
        'a session for another address' => sgSession('pat@example.test'),
    ];
    $sessions = DB::table('tenant_luna.customer_sessions')->orderBy('id')->get()->all();

    // Neither credential: the malformed request it always was, the embed's contract.
    sgSend($send, $this, $bySession['uuid'], null, '2026-11-04 15:00')->assertStatus(400);

    // The answer every miss below must be: a wrong token's, no cookie anywhere.
    $miss = sgAnswer(sgSend($send, $this, $bySession['uuid'], ManageToken::generate()->plain, '2026-11-04 15:00')->assertNotFound());

    $this->withCredentials();
    $misses = [];

    foreach ($secrets as $case => $secret) {
        $this->withCookie(MyBookingsCookie::NAME, $secret);
        $misses[$case] = sgAnswer(sgSend($send, $this, $bySession['uuid'], null, '2026-11-04 15:00'));
    }

    $this->withCookie(MyBookingsCookie::NAME, $live);

    foreach ([
        'another address\'s booking' => [$theirs['uuid'], null],
        'an unknown booking' => [(string) Str::uuid(), null],
        'an identifier that is no uuid' => ['not-a-uuid-at-all', null],
        'a booking past the derived expiry' => [$ended, null],
        'a live session beside a wrong token' => [$bySession['uuid'], ManageToken::generate()->plain],
        'a live session beside another booking\'s token' => [$bySession['uuid'], $theirs['token']],
    ] as $case => [$uuid, $token]) {
        $misses[$case] = sgAnswer(sgSend($send, $this, $uuid, $token, '2026-11-04 15:00'));
    }

    expect($misses)->toHaveCount(10)
        ->and(array_unique(array_map(fn (array $answer): string => json_encode($answer, JSON_THROW_ON_ERROR), $misses)))->toBe([array_key_first($misses) => json_encode($miss, JSON_THROW_ON_ERROR)])
        ->and(sgStatuses([$bySession['uuid'], $byToken['uuid'], $theirs['uuid']]))->toBe(['pending', 'pending', 'pending']);

    // Both present, the token judges: the booking's own token beside another address's session.
    $this->withCookie(MyBookingsCookie::NAME, $secrets['a session for another address']);
    $accepted(sgSend($send, $this, $byToken['uuid'], $byToken['token'], '2026-11-04 16:00'), $byToken['uuid'], app(TenantUrls::class)->manageLink(Slug::of('luna'), $byToken['uuid'], $byToken['token']));

    // The session alone, for the address the booking was made under.
    $this->withCookie(MyBookingsCookie::NAME, $live);
    $accepted(sgSend($send, $this, $bySession['uuid'], null, '2026-11-04 15:00'), $bySession['uuid'], app(TenantUrls::class)->myBookings(Slug::of('luna')));

    // Nothing is written for the session: its row, and every other, as they were.
    expect(DB::table('tenant_luna.customer_sessions')->orderBy('id')->get()->all())->toEqual($sessions);
})->with('the routes behind the guard');

it('measures the derived expiry for a session as for a token: a booking whose last segment ended a second inside the lifetime reads, and one at the lifetime does not', function () {
    $live = sgSession(SG_CASEY);
    // The lifetime is elapsed days, counted in UTC as the guard counts it: thirty days back on
    // the Athens clock would cross the October change and land an hour further back.
    $now = FixtureDay::pinClock()->now()->setTimezone(new DateTimeZone('UTC'));
    $lifetime = new DateInterval(sprintf('P%dD', ManageTokenGuard::LIFETIME_DAYS));
    // Each booking is half an hour long, so it starts half an hour before the end it is named by.
    $startOf = fn (DateTimeImmutable $end): string => $end->sub(new DateInterval('PT30M'))->format('Y-m-d H:i:s');
    // Completed, so the two rows a second apart block nothing and the exclusion constraint passes
    // them.
    $inside = mybBooking(SG_CASEY, $startOf($now->sub($lifetime)->add(new DateInterval('PT1S'))), 'completed');
    $at = mybBooking(SG_CASEY, $startOf($now->sub($lifetime)), 'completed');

    $this->withCredentials()->withCookie(MyBookingsCookie::NAME, $live);

    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$inside))->assertOk()->assertJsonPath('uuid', $inside);
    $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$at))->assertNotFound();
});

it('cancels from the list through the route with the cookie and no token: the use case\'s answer, the customer as the actor, the trail a token\'s cancel writes, and the write family\'s budget shared with a token\'s calls', function () {
    $bySession = sgHold($this, FixtureDay::START);
    $byToken = sgHold($this, '2026-11-03 11:00');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$byToken['uuid'].'/cancel'), ['token' => $byToken['token']])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE)
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 1));

    $this->withCredentials()->withCookie(MyBookingsCookie::NAME, sgSession(SG_CASEY))
        ->postJson(tenantUrl('luna', '/api/v1/bookings/'.$bySession['uuid'].'/cancel'), [])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE)
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 2))
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonMissingPath('manage_token');

    expect(sgShape(sgTrail($bySession['uuid'])))->toBe(sgShape(sgTrail($byToken['uuid'])))
        ->and(sgTrail($bySession['uuid']))->toHaveCount(2)
        ->and(sgTrail($bySession['uuid'])[1] ?? '')->toBe('customer cancelled {"status_to": "cancelled", "hold_class": "checkout", "status_from": "pending"}');
});

it('reschedules from the list through the route with the cookie and no token: the use case\'s answer, the customer as the actor, the trail a token\'s move writes, and the write family\'s budget shared with a token\'s calls', function () {
    $bySession = sgHold($this, FixtureDay::START);
    $byToken = sgHold($this, '2026-11-03 11:00');

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$byToken['uuid'].'/reschedule'), ['token' => $byToken['token'], 'start' => sgStart('2026-11-04 16:00')])
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 1));

    $moved = $this->withCredentials()->withCookie(MyBookingsCookie::NAME, sgSession(SG_CASEY))
        ->postJson(tenantUrl('luna', '/api/v1/bookings/'.$bySession['uuid'].'/reschedule'), ['start' => sgStart('2026-11-04 15:00')])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE)
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 2))
        ->assertJsonMissingPath('manage_token');

    expect($moved->json('items.0.start'))->toBe('2026-11-04T13:00:00+00:00')
        ->and(sgShape(sgTrail($bySession['uuid'])))->toBe(sgShape(sgTrail($byToken['uuid'])))
        ->and(sgTrail($bySession['uuid']))->toHaveCount(2)
        ->and(sgTrail($bySession['uuid'])[1] ?? '')->toStartWith('customer rescheduled ');
});

it('sends a session\'s pay hop on to the provider with My bookings as the way back and seals nothing, since no plaintext token was presented', function () {
    connectPaymentFake();
    $id = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $this->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => SG_CASEY,
        'items' => [['service_id' => $id('services', 'Colour'), 'staff_id' => $id('staff', 'Elena Papadaki')]],
        'start' => sgStart(FixtureDay::START),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']));
    $back = app(TenantUrls::class)->myBookings(Slug::of('luna'));

    $this->withCookie(MyBookingsCookie::NAME, sgSession(SG_CASEY))
        ->get(tenantUrl('luna', '/pay/'.$held['uuid']))
        ->assertStatus(303)
        ->assertRedirect($back.'?paydesk_session=pd_1')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    expect(paymentFake()->sessions['pd_1']['returnUrl'] ?? null)->toBe($back)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('payment_ref'))->toBe('pd_1')
        ->and(DB::scalar('select manage_token_sealed is null from tenant_luna.bookings where uuid = ?', [$held['uuid']]))->toBeTrue();
});

it('reads no session on a request the browser marked cross-site: the cookie alone answers as a request carrying no credential, the malformed request\'s answer byte for byte, writing nothing, while a token on the same cross-site request is judged as ever (P9F-L17-a)', function (Closure $send, Closure $accepted) {
    $bySession = sgHold($this, FixtureDay::START);
    $byToken = sgHold($this, '2026-11-03 11:00');
    $live = sgSession(SG_CASEY);
    $sessions = DB::table('tenant_luna.customer_sessions')->orderBy('id')->get()->all();
    $none = sgAnswer(sgSend($send, $this, $bySession['uuid'], null, '2026-11-04 15:00')->assertStatus(400));

    // A top-level navigation from another site carries a Lax cookie; the browser says where it
    // came from, and the guard takes the session from no such request.
    $this->withCredentials()->withCookie(MyBookingsCookie::NAME, $live)->withHeader('Sec-Fetch-Site', 'cross-site');
    $crossSite = sgAnswer(sgSend($send, $this, $bySession['uuid'], null, '2026-11-04 15:00'));

    expect($crossSite)->toBe($none)
        ->and(sgStatuses([$bySession['uuid'], $byToken['uuid']]))->toBe(['pending', 'pending'])
        ->and(sgTrail($bySession['uuid']))->toHaveCount(1)
        ->and(DB::table('tenant_luna.customer_sessions')->orderBy('id')->get()->all())->toEqual($sessions);

    // The token beside the cookie on a cross-site request: judged by the token, as ever.
    $accepted(sgSend($send, $this, $byToken['uuid'], $byToken['token'], '2026-11-04 16:00'), $byToken['uuid'], app(TenantUrls::class)->manageLink(Slug::of('luna'), $byToken['uuid'], $byToken['token']));
})->with('the routes behind the guard');

it('sends a session\'s pay hop on to the provider wherever the browser marks the request same-origin, same-site or typed in, or marks it not at all, and refuses it marked cross-site with the guard\'s 400 page, the provider never asked (P9F-L17-a)', function (?string $site, bool $admitted) {
    connectPaymentFake();
    $id = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $this->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => SG_CASEY,
        'items' => [['service_id' => $id('services', 'Colour'), 'staff_id' => $id('staff', 'Elena Papadaki')]],
        'start' => sgStart(FixtureDay::START),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']));
    $this->withCookie(MyBookingsCookie::NAME, sgSession(SG_CASEY));

    if ($site !== null) {
        $this->withHeader('Sec-Fetch-Site', $site);
    }

    $answer = $this->get(tenantUrl('luna', '/pay/'.$held['uuid']));

    if ($admitted) {
        $answer->assertStatus(303)->assertRedirect(app(TenantUrls::class)->myBookings(Slug::of('luna')).'?paydesk_session=pd_1');
    } else {
        $answer->assertStatus(400)->assertSee(pageSentence('pages.pay.refused.title'))->assertHeader('Cache-Control', 'no-store, private');
    }

    expect(DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('payment_ref'))->toBe($admitted ? 'pd_1' : null)
        ->and(paymentFake()->asked)->toBe($admitted ? [['checkoutUrl', $held['uuid']]] : []);
})->with([
    'same-origin, the tenant\'s own page' => ['same-origin', true],
    'same-site, another host under the central domain' => ['same-site', true],
    'none, typed in or a bookmark' => ['none', true],
    'no header, a browser that sends none' => [null, true],
    'cross-site, a page on another site' => ['cross-site', false],
]);

it('takes the minted link, the hold answer\'s checkout_url, on to the provider from a page on another site, the tenant\'s own site where the embed runs, with the My bookings cookie beside it or without it (P9F-L17-a)', function (bool $cookie) {
    connectPaymentFake();
    $id = fn (string $table, string $name): mixed => DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');
    $held = $this->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => SG_CASEY,
        'items' => [['service_id' => $id('services', 'Colour'), 'staff_id' => $id('staff', 'Elena Papadaki')]],
        'start' => sgStart(FixtureDay::START),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']) && is_string($held['checkout_url']));
    $back = app(TenantUrls::class)->manageLink(Slug::of('luna'), $held['uuid'], $held['manage_token']);

    if ($cookie) {
        $this->withCookie(MyBookingsCookie::NAME, sgSession(SG_CASEY));
    }

    $this->withHeader('Sec-Fetch-Site', 'cross-site')->get($held['checkout_url'])
        ->assertStatus(303)
        ->assertRedirect($back.'&paydesk_session=pd_1');

    expect(paymentFake()->sessions['pd_1']['returnUrl'] ?? null)->toBe($back)
        ->and(DB::scalar('select manage_token_sealed is not null from tenant_luna.bookings where uuid = ?', [$held['uuid']]))->toBeTrue();
})->with(['with the cookie' => [true], 'without it' => [false]]);
