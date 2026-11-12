<?php

use App\Application\Clock;
use App\Domain\Booking\ManageToken;
use App\Http\ErrorEnvelope;
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
 * POST /api/v1/bookings/{uuid}/cancel on the wire: what the customer gets back, what each
 * refusal looks like in the envelope, and the budget the write family shares.
 *
 * Not wrapped in a transaction: the route enters the transaction runner, which refuses to open
 * inside another. The central tables are truncated before each test and the demo schemas
 * dropped after.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00. The clock stands on the Monday before, which is outside the 24-hour window; the one
 * test about a closed window moves it.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    givingUpClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function givingUpClockAt(string $utc): void
{
    app()->instance(Clock::class, FixedClock::at($utc));
}

function givingUpId(string $table, string $name): int
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
function givingUpHold(TestCase $test, string $start = FixtureDay::START): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => givingUpId('services', 'Haircut'), 'staff_id' => givingUpId('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

function givingUpUrl(string $uuid): string
{
    return tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel');
}

/**
 * The cancel as the manage view sends it: the token in the body, which is where a POST
 * carries it.
 *
 * @return TestResponse<Response>
 */
function givingUpCancel(TestCase $test, string $uuid, ?string $token): TestResponse
{
    return $test->postJson(givingUpUrl($uuid), $token === null ? [] : ['token' => $token]);
}

it('answers 200 with the guest view showing the booking cancelled and no hold left on it, no-store, and the write family\'s limit rather than the general one', function () {
    ['uuid' => $uuid, 'token' => $token] = givingUpHold($this);
    $read = $this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json();

    $response = givingUpCancel($this, $uuid, $token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE);
    $cancelled = $response->json();

    assert(is_array($read) && is_array($cancelled));

    expect($cancelled['status'])->toBe('cancelled')
        ->and($cancelled['hold_class'])->toBeNull()
        ->and($cancelled['hold_expires_at'])->toBeNull()
        ->and($cancelled)->not->toHaveKey('manage_token')
        ->and($cancelled['items'])->toBe($read['items'])
        ->and($cancelled['total_minor'])->toBe($read['total_minor'])
        ->and($this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json('status'))->toBe('cancelled')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'cancelled')->count())->toBe(1);
});

it('answers a booking that is no longer cancellable 409 not_cancellable in the envelope, with a sentence written to be shown', function () {
    ['uuid' => $uuid, 'token' => $token] = givingUpHold($this);

    givingUpCancel($this, $uuid, $token)->assertOk();

    givingUpCancel($this, $uuid, $token)->assertStatus(409)->assertExactJson([
        'code' => 'not_cancellable',
        'message' => 'not_cancellable',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::NOT_CANCELLABLE_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.audit_log')->where('action', 'cancelled')->count())->toBe(1);
});

it('answers a customer inside the cancellation window 403 window_closed, and leaves the booking alone for them to ring about', function () {
    ['uuid' => $uuid, 'token' => $token] = givingUpHold($this);

    // 10:00 in Athens is 08:00 UTC and the service's window is 24 hours, so a second past
    // 08:00 UTC on the Monday is too late.
    givingUpClockAt('2026-11-02 08:00:01');

    givingUpCancel($this, $uuid, $token)->assertStatus(403)->assertExactJson([
        'code' => 'window_closed',
        'message' => 'window_closed',
        'data' => ['status' => 403, 'detail' => apiSentence(ErrorEnvelope::WINDOW_CLOSED_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'cancelled')->count())->toBe(0);
});

it('answers every way of not holding the credential the guard\'s one refusal, and a request carrying no token at all as a malformed one', function () {
    ['uuid' => $uuid, 'token' => $token] = givingUpHold($this);
    $other = givingUpHold($this, '2026-11-03 11:00');

    $notFound = [
        'code' => 'not_found',
        'message' => 'not_found',
        'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')],
    ];

    givingUpCancel($this, $uuid, ManageToken::generate()->plain)->assertNotFound()->assertExactJson($notFound);
    givingUpCancel($this, $uuid, $other['token'])->assertNotFound()->assertExactJson($notFound);
    givingUpCancel($this, (string) Str::uuid(), $token)->assertNotFound()->assertExactJson($notFound);
    givingUpCancel($this, 'not-a-uuid-at-all', $token)->assertNotFound()->assertExactJson($notFound);

    givingUpCancel($this, $uuid, null)->assertStatus(400)->assertExactJson([
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'cancelled')->count())->toBe(0);
});

it('shares one budget with the rest of the POST family, so a customer who confirmed and then cancelled has spent two of the twenty and none of the general one', function () {
    ['uuid' => $uuid, 'token' => $token] = givingUpHold($this);

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/confirm'), ['token' => $token])
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 1));

    givingUpCancel($this, $uuid, $token)
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::BOOKING_WRITE_REQUESTS_PER_MINUTE - 2));

    $this->getJson(tenantUrl('luna', '/api/v1/services'))
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', (string) (AppServiceProvider::API_REQUESTS_PER_MINUTE - 1));
});
