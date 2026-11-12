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
 * DELETE /api/v1/holds/{uuid} on the wire: the release the booking app fires on page hide and
 * on "Pick another time". The token travels in the query string, because a DELETE carries no
 * body.
 *
 * Not wrapped in a transaction: the route enters the transaction runner, which refuses to open
 * inside another.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00. No window applies to a release at all, which is part of what this proves: the clock
 * sits inside the service's cancellation window and the release goes through anyway.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function handingBackId(string $table, string $name): int
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
function handingBackHold(TestCase $test, string $start = FixtureDay::START): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => handingBackId('services', 'Haircut'), 'staff_id' => handingBackId('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

/**
 * The release as the booking app sends it: the token in the query string.
 *
 * @return TestResponse<Response>
 */
function handingBackRelease(TestCase $test, string $uuid, ?string $token): TestResponse
{
    $url = tenantUrl('luna', '/api/v1/holds/'.$uuid);

    return $test->deleteJson($token === null ? $url : $url.'?token='.$token);
}

it('answers 204 and no body at all, leaves the booking cancelled and the slot free, and counts against the general limit rather than the write family\'s', function () {
    ['uuid' => $uuid, 'token' => $token] = handingBackHold($this);

    $response = handingBackRelease($this, $uuid, $token)
        ->assertNoContent()
        ->assertHeader('X-RateLimit-Limit', (string) AppServiceProvider::API_REQUESTS_PER_MINUTE);

    expect($response->getContent())->toBe('')
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('cancelled')
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('hold_class'))->toBeNull()
        ->and(DB::scalar('select bool_or(blocks) from tenant_luna.booking_items'))->toBeFalse()
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'released')->count())->toBe(1)
        // The credential still reads the booking it manages: the customer is shown what
        // happened rather than a neutral panel.
        ->and($this->getJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'?token='.$token))->assertOk()->json('status'))->toBe('cancelled');
});

it('answers a hold that is no longer pending 409 not_releasable in the envelope, which is what a release arriving after the sweeper or after a confirm gets', function () {
    ['uuid' => $uuid, 'token' => $token] = handingBackHold($this);

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/confirm'), ['token' => $token])->assertOk();

    handingBackRelease($this, $uuid, $token)->assertStatus(409)->assertExactJson([
        'code' => 'not_releasable',
        'message' => 'not_releasable',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::NOT_RELEASABLE_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('confirmed')
        ->and(DB::scalar('select bool_or(blocks) from tenant_luna.booking_items'))->toBeTrue()
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'released')->count())->toBe(0);
});

it('answers every way of not holding the credential the guard\'s one refusal, and a request carrying no token at all as a malformed one', function () {
    ['uuid' => $uuid, 'token' => $token] = handingBackHold($this);
    $other = handingBackHold($this, '2026-11-03 11:00');

    $notFound = [
        'code' => 'not_found',
        'message' => 'not_found',
        'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')],
    ];

    handingBackRelease($this, $uuid, ManageToken::generate()->plain)->assertNotFound()->assertExactJson($notFound);
    handingBackRelease($this, $uuid, $other['token'])->assertNotFound()->assertExactJson($notFound);
    handingBackRelease($this, (string) Str::uuid(), $token)->assertNotFound()->assertExactJson($notFound);
    handingBackRelease($this, 'not-a-uuid-at-all', $token)->assertNotFound()->assertExactJson($notFound);

    handingBackRelease($this, $uuid, null)->assertStatus(400)->assertExactJson([
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('pending')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'released')->count())->toBe(0);
});

it('releases a hold the customer would be too late to cancel, because no window applies to handing back what they took a moment ago', function () {
    ['uuid' => $uuid, 'token' => $token] = handingBackHold($this);

    // Inside the service's 24-hour cancellation window: a cancel here answers 403.
    app()->instance(Clock::class, FixedClock::at('2026-11-02 08:00:01'));

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/cancel'), ['token' => $token])->assertStatus(403);
    handingBackRelease($this, $uuid, $token)->assertNoContent();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('status'))->toBe('cancelled')
        ->and(DB::table('tenant_luna.audit_log')->where('action', 'released')->count())->toBe(1);
});
