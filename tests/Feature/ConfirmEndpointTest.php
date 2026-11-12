<?php

use App\Application\Clock;
use App\Domain\Booking\ManageToken;
use App\Http\ErrorEnvelope;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * POST /api/v1/bookings/{uuid}/confirm on the wire: what the customer gets back, what each
 * refusal looks like in the envelope, and the limit the family shares.
 *
 * Not wrapped in a transaction: the route enters the transaction runner, which refuses to open
 * inside another. The central tables are truncated before each test and the demo schemas
 * dropped after.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00. The clock stands on the Monday before.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    DB::purge('settle_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function settleId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A hold taken over the wire, the way the booking app takes one.
 *
 * @return array{uuid: string, token: string}
 */
function settleHold(TestCase $test, string $service = 'Haircut', string $start = FixtureDay::START): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => settleId('services', $service), 'staff_id' => settleId('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

/**
 * A hold written straight to the rows, already past its expiry by the database's clock, with a
 * token the test holds. The hold path writes nothing lapsed, and what is on trial is the answer
 * to a customer who left the tab open.
 *
 * @return array{uuid: string, token: string}
 */
function settleLapsed(): array
{
    $token = ManageToken::generate();
    $uuid = (string) Str::uuid();

    $bookingId = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid,
        'status' => 'pending',
        'hold_class' => 'checkout',
        'hold_expires_at' => DB::raw("now() - interval '1 minute'"),
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'total_minor' => 3500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'on_approval_timeout' => 'expire',
        'manage_token_hash' => $token->hash(),
    ]);

    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $bookingId,
        'sort' => 0,
        'service_id' => settleId('services', 'Haircut'),
        'staff_id' => settleId('staff', 'Elena Papadaki'),
        'start_utc' => '2026-11-03 09:00:00.000000+00:00',
        'end_utc' => '2026-11-03 09:45:00.000000+00:00',
        'block_start_utc' => '2026-11-03 09:00:00.000000+00:00',
        'block_end_utc' => '2026-11-03 09:45:00.000000+00:00',
        'price_minor' => 3500,
        'blocks' => true,
    ]);

    return ['uuid' => $uuid, 'token' => $token->plain];
}

function settleUrl(string $uuid, string $slug = 'luna'): string
{
    return tenantUrl($slug, '/api/v1/bookings/'.$uuid.'/confirm');
}

/**
 * The confirm as the booking app sends it: the token in the body, which is where a POST
 * carries it.
 *
 * @return TestResponse<Response>
 */
function settleConfirm(TestCase $test, string $uuid, ?string $token): TestResponse
{
    return $test->postJson(settleUrl($uuid), $token === null ? [] : ['token' => $token]);
}

/**
 * A connection of its own to the same database, for holding a row lock while a request waits
 * for it.
 */
function settleProbe(): Connection
{
    /** @var array<string, mixed> $pgsql */
    $pgsql = config('database.connections.pgsql');
    config(['database.connections.settle_probe' => $pgsql]);

    return DB::connection('settle_probe');
}

it('answers 200 with the guest view showing the booking confirmed and no hold left on it, no-store, and the family limit rather than the general one', function () {
    ['uuid' => $uuid, 'token' => $token] = settleHold($this);
    $read = $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json();

    $response = settleConfirm($this, $uuid, $token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE);
    $confirmed = $response->json();

    assert(is_array($read) && is_array($confirmed));

    expect($confirmed['status'])->toBe('confirmed')
        ->and($confirmed['hold_class'])->toBeNull()
        ->and($confirmed['hold_expires_at'])->toBeNull()
        ->and($confirmed)->not->toHaveKey('manage_token')
        ->and($confirmed)->not->toHaveKey('checkout_url')
        ->and($confirmed)->not->toHaveKey('id')
        ->and($confirmed['items'])->toBe($read['items'])
        ->and($confirmed['total_minor'])->toBe($read['total_minor'])
        ->and($this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json('status'))->toBe('confirmed');
});

it('answers every way of not holding the credential the guard\'s one refusal, and a request carrying no token at all as a malformed one', function () {
    ['uuid' => $uuid, 'token' => $token] = settleHold($this);
    $other = settleHold($this, 'Haircut', '2026-11-03 11:00');

    $notFound = [
        'code' => 'not_found',
        'message' => 'not_found',
        'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')],
    ];

    settleConfirm($this, $uuid, ManageToken::generate()->plain)->assertNotFound()->assertExactJson($notFound);
    settleConfirm($this, $uuid, $other['token'])->assertNotFound()->assertExactJson($notFound);
    settleConfirm($this, (string) Str::uuid(), $token)->assertNotFound()->assertExactJson($notFound);
    settleConfirm($this, 'not-a-uuid-at-all', $token)->assertNotFound()->assertExactJson($notFound);

    settleConfirm($this, $uuid, null)->assertStatus(400)->assertExactJson([
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0);
});

it('answers a booking that is no longer waiting to be confirmed 409 not_confirmable in the envelope, with a sentence written to be shown', function () {
    ['uuid' => $uuid, 'token' => $token] = settleHold($this);

    settleConfirm($this, $uuid, $token)->assertOk();

    settleConfirm($this, $uuid, $token)->assertStatus(409)->assertExactJson([
        'code' => 'not_confirmable',
        'message' => 'not_confirmable',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::NOT_CONFIRMABLE_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(1);
});

it('answers a hold whose expiry has passed 410 hold_expired, leaving the booking as it was for the sweeper', function () {
    ['uuid' => $uuid, 'token' => $token] = settleLapsed();

    settleConfirm($this, $uuid, $token)->assertStatus(410)->assertExactJson([
        'code' => 'hold_expired',
        'message' => 'hold_expired',
        'data' => ['status' => 410, 'detail' => apiSentence(ErrorEnvelope::HOLD_EXPIRED_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->count())->toBe(0);
});

it('answers 503 busy with a Retry-After when the booking row lock is not granted inside the timeout, which is the writer\'s own failure surfacing rather than a retry', function () {
    ['uuid' => $uuid, 'token' => $token] = settleHold($this);
    $probe = settleProbe();

    // A second connection holds the row the transition locks at step 4, so the request waits on
    // a real lock and is aborted by the lock_timeout the runner sets. Nothing is staged: what
    // the route answers is whatever the driver's 55P03 becomes on the way out.
    $probe->beginTransaction();
    $probe->select('select id from tenant_luna.bookings where uuid = ?::uuid for update', [$uuid]);

    try {
        settleConfirm($this, $uuid, $token)
            ->assertStatus(503)
            ->assertHeader('Retry-After', ErrorEnvelope::RETRY_AFTER_BUSY)
            ->assertExactJson([
                'code' => 'busy',
                'message' => 'busy',
                'data' => ['status' => 503, 'detail' => apiSentence(ErrorEnvelope::BUSY_DETAIL)],
            ]);
    } finally {
        $probe->rollBack();
    }

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'confirmed')->count())->toBe(0);

    settleConfirm($this, $uuid, $token)->assertOk();
});

it('counts the POST family against its own limit of twenty a minute per address and answers the twenty-first 429, while another address still passes', function () {
    ['uuid' => $uuid] = settleHold($this);
    $wrong = ManageToken::generate()->plain;
    $statuses = [];

    // A wrong token, so the budget is spent without confirming twenty times: the limiter runs
    // in front of the guard and counts the request either way.
    for ($request = 1; $request <= AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE; $request++) {
        $statuses[settleConfirm($this, $uuid, $wrong)->status()] = true;
    }

    expect(array_keys($statuses))->toBe([404]);

    settleConfirm($this, $uuid, $wrong)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertExactJson([
            'code' => 'rate_limited',
            'message' => 'rate_limited',
            'data' => ['status' => 429, 'detail' => apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)],
        ]);

    $this->call('POST', settleUrl($uuid), server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], content: (string) json_encode(['token' => $wrong]))->assertNotFound();
});

it('spends none of the general budget, so a customer confirming has not spent a catalog read', function () {
    ['uuid' => $uuid, 'token' => $token] = settleHold($this);

    settleConfirm($this, $uuid, $token)->assertOk();

    $this->getJson(tenantUrl('luna', '/api/v1/services'))
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::API_REQUESTS_PER_MINUTE - 1));
});
