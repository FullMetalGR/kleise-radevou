<?php

use App\Application\Clock;
use App\Domain\Booking\ManageToken;
use App\Http\ErrorEnvelope;
use App\Http\Requests\Instant;
use App\Providers\AppServiceProvider;
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
 * POST /api/v1/bookings/{uuid}/reschedule on the wire: what the customer gets back when the
 * booking moves, what each refusal looks like in the envelope, and the budget the write family
 * shares.
 *
 * Not wrapped in a transaction: the route enters the transaction runner, which refuses to open
 * inside another. The central tables are truncated before each test and the demo schemas
 * dropped after.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday and 2026-11-04 a
 * Wednesday, both of which Elena works 09:00 to 13:00 and 14:00 to 18:00. The clock stands on
 * the Monday before, which is outside the 24-hour window; the one test about a closed window
 * moves it.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    movingClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function movingClockAt(string $utc): void
{
    app()->instance(Clock::class, FixedClock::at($utc));
}

function movingId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * An Athens wall-clock time spelled the way the wire spells a start.
 */
function movingStart(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339);
}

/**
 * A hold taken over the wire, the way the booking app takes one.
 *
 * @return array{uuid: string, token: string}
 */
function movingHold(TestCase $test, string $start = FixtureDay::START): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => movingId('services', 'Haircut'), 'staff_id' => movingId('staff', 'Elena Papadaki')]],
        'start' => movingStart($start),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

function movingUrl(string $uuid): string
{
    return tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule');
}

/**
 * The move as the reschedule dialog sends it: the token and the new start in the body, which is
 * where a POST carries the token.
 *
 * @param  array<string, mixed>|null  $body  the body as sent, or null for the ordinary one
 * @return TestResponse<Response>
 */
function movingReschedule(TestCase $test, string $uuid, ?string $token, string $start = '2026-11-04 15:00', ?array $body = null): TestResponse
{
    return $test->postJson($body === null
        ? movingUrl($uuid)
        : movingUrl($uuid), $body ?? array_filter([
            'token' => $token,
            'start' => movingStart($start),
        ], fn (mixed $value): bool => $value !== null));
}

it('answers 200 with the guest view showing the new items, the status, the hold and the total standing, no-store, and the write family\'s limit rather than the general one', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);
    $read = $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json();

    $moved = movingReschedule($this, $uuid, $token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE)
        ->json();

    assert(is_array($read) && is_array($moved) && is_array($read['items']) && is_array($moved['items']));
    assert(is_array($read['items'][0]) && is_array($moved['items'][0]));

    expect($moved['status'])->toBe($read['status'])
        ->and($moved['hold_class'])->toBe($read['hold_class'])
        ->and($moved['hold_expires_at'])->toBe($read['hold_expires_at'])
        ->and($moved['total_minor'])->toBe($read['total_minor'])
        ->and($moved)->not->toHaveKey('manage_token')
        ->and($moved['items'])->toHaveCount(1)
        // The sold member, the sold service and the sold price ride across the move; only the
        // range is new, and the customer is shown the row rather than what was asked for.
        ->and($moved['items'][0]['staff_id'])->toBe($read['items'][0]['staff_id'])
        ->and($moved['items'][0]['service_id'])->toBe($read['items'][0]['service_id'])
        ->and($moved['items'][0]['price_minor'])->toBe($read['items'][0]['price_minor'])
        ->and($moved['items'][0]['start'])->toBe('2026-11-04T13:00:00+00:00')
        ->and($moved['items'][0]['end'])->toBe('2026-11-04T13:45:00+00:00')
        ->and($this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json('items'))->toBe($moved['items'])
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->count())->toBe(1);
});

it('moves a booking to a free start off the tenant\'s start interval, which the dialog is not offered: the interval shapes the offer and not the move', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);
    $offered = $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/availability?'.http_build_query(['token' => $token, 'from' => '2026-11-04', 'to' => '2026-11-05'])))
        ->assertOk()
        ->json('2026-11-04');

    expect(DB::table('tenant_luna.settings')->where('id', 1)->value('start_interval_min'))->toBe(15)
        ->and($offered)->toContain('2026-11-04T13:00:00+00:00')
        ->and($offered)->not->toContain('2026-11-04T13:05:00+00:00');

    expect(movingReschedule($this, $uuid, $token, '2026-11-04 15:05')->assertOk()->json('items.0.start'))->toBe('2026-11-04T13:05:00+00:00');
});

it('answers a start the tenant is not open to 409 unavailable naming the segment, and a start somebody else holds 409 overlap naming it, each with a sentence written to be shown', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);
    $rival = movingHold($this, '2026-11-04 15:00');

    // 13:15 on the Wednesday is inside Elena's lunch break: nobody else is in it, the tenant is
    // simply not open there.
    movingReschedule($this, $uuid, $token, '2026-11-04 13:15')->assertStatus(409)->assertExactJson([
        'code' => 'unavailable',
        'message' => 'unavailable',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL), 'segment' => 0],
    ]);

    movingReschedule($this, $uuid, $token, '2026-11-04 15:00')->assertStatus(409)->assertExactJson([
        'code' => 'overlap',
        'message' => 'overlap',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::OVERLAP_DETAIL), 'segment' => 0],
    ]);

    expect($rival['uuid'])->not->toBe($uuid)
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->count())->toBe(0);
});

it('answers a booking a move does not reach 409 with its own reason, the lapsed hold among them and never a 410', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $token])->assertOk();

    movingReschedule($this, $uuid, $token)->assertStatus(409)->assertExactJson([
        'code' => 'not_reschedulable',
        'message' => 'not_reschedulable',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::NOT_RESCHEDULABLE_DETAIL)],
    ]);

    $lapsed = movingHold($this, '2026-11-03 11:00');
    DB::table('tenant_luna.bookings')->where('uuid', $lapsed['uuid'])->update(['hold_expires_at' => DB::raw("now() - interval '1 minute'")]);

    // 409 and not 410: the 410 is kept for a hold a confirm was about, and that is
    // the single exception.
    movingReschedule($this, $lapsed['uuid'], $lapsed['token'])->assertStatus(409)->assertExactJson([
        'code' => 'hold_lapsed',
        'message' => 'hold_lapsed',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::HOLD_LAPSED_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->count())->toBe(0);
});

it('answers a customer inside the reschedule window 403 window_closed, and leaves the booking where it is for them to ring about', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);

    // 10:00 in Athens is 08:00 UTC and the service's window is 24 hours, so a second past
    // 08:00 UTC on the Monday is too late, whatever start is being asked for.
    movingClockAt('2026-11-02 08:00:01');

    movingReschedule($this, $uuid, $token)->assertStatus(403)->assertExactJson([
        'code' => 'window_closed',
        'message' => 'window_closed',
        'data' => ['status' => 403, 'detail' => apiSentence(ErrorEnvelope::WINDOW_CLOSED_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->count())->toBe(0);
});

it('answers every way of not holding the credential the guard\'s one refusal, identically, and a request carrying no token at all as a malformed one', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);
    $other = movingHold($this, '2026-11-03 11:00');

    $notFound = [
        'code' => 'not_found',
        'message' => 'not_found',
        'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')],
    ];

    movingReschedule($this, $uuid, ManageToken::generate()->plain)->assertNotFound()->assertExactJson($notFound);
    movingReschedule($this, $uuid, $other['token'])->assertNotFound()->assertExactJson($notFound);
    movingReschedule($this, (string) Str::uuid(), $token)->assertNotFound()->assertExactJson($notFound);
    movingReschedule($this, 'not-a-uuid-at-all', $token)->assertNotFound()->assertExactJson($notFound);

    movingReschedule($this, $uuid, null)->assertStatus(400)->assertExactJson([
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->count())->toBe(0);
});

it('refuses a start that is not an instant with the sentence the field carries, once the credential has been judged good', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);
    $invalid = [
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(Instant::SENTENCE)],
    ];

    foreach ([null, '', 'tomorrow', '2026-11-04 15:00', '2026-02-30T15:00:00+02:00', '2026-11-04T25:00:00+02:00'] as $start) {
        movingReschedule($this, $uuid, $token, body: array_filter(
            ['token' => $token, 'start' => $start],
            fn (mixed $value): bool => $value !== null,
        ))->assertStatus(400)->assertExactJson($invalid);
    }

    // The credential is judged first: a wrong one beside a bad start is answered as the wrong
    // credential it is, so the field's sentence reaches the token's holder alone.
    movingReschedule($this, $uuid, str_repeat('0', strlen($token)), body: ['token' => str_repeat('0', strlen($token)), 'start' => 'tomorrow'])
        ->assertNotFound();

    expect(DB::table('tenant_luna.audit_log')->where('action', 'rescheduled')->count())->toBe(0);
});

it('shares one budget with the rest of the POST family, so a customer who moved and then cancelled has spent two of the twenty and none of the general one', function () {
    ['uuid' => $uuid, 'token' => $token] = movingHold($this);

    movingReschedule($this, $uuid, $token)
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 1));

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $token])
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 2));

    $this->getJson(tenantUrl('luna', '/api/v1/services'))
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::API_REQUESTS_PER_MINUTE - 1));
});
