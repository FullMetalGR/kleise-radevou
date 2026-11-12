<?php

use App\Application\Clock;
use App\Domain\Booking\ManageToken;
use App\Http\BookingPayload;
use App\Http\ErrorEnvelope;
use App\Http\ManagedBooking;
use App\Http\ManageTokenGuard;
use App\Http\Middleware\RequiresManageToken;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;

pest()->use(DatabaseTruncation::class);

/*
 * GET /api/v1/bookings/{uuid} on the wire, and the guard in front of it: what a customer
 * holding their token is shown, what every way of not holding it is answered with, and that
 * those answers cannot be told apart.
 *
 * Not wrapped in a transaction: one test holds a booking through the route that writes, and
 * that route enters the transaction runner, which refuses to open inside another. The central
 * tables are truncated before each test and the demo schemas dropped and the central rows
 * deleted after.
 *
 * The bookings the guard is asked about are in the past, because the expiry is measured from a
 * booking's last segment and the hold path writes only bookings in the future. They are written
 * straight to the rows for that reason, with the clock standing where the tests put it.
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

/**
 * The read as a magic link spells it: the booking in the path, the token in the query, which is
 * where a request with no body carries it.
 */
function manageUrl(string $uuid, ?string $token = null, string $slug = 'luna'): string
{
    return tenantUrl($slug, '/api/v1/bookings/'.$uuid.($token === null ? '' : '?token='.$token));
}

function manageNow(): DateTimeImmutable
{
    return app(Clock::class)->now();
}

/**
 * An instant as the server writes one: UTC text with the offset written out, never a bound date
 * object, which the framework formats without its zone.
 */
function manageInstant(DateTimeImmutable $instant): string
{
    return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
}

function manageId(string $table, string $name, string $schema = 'tenant_luna'): int
{
    $id = DB::table($schema.'.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A booking that has already happened, its segments named by how many days before now each one
 * ended. The chain runs forward in time and every segment lasts an hour. Nothing blocks: a
 * finished booking holds no capacity, which is also what keeps two of these in one test from
 * meeting the exclusion constraint.
 *
 * @param  non-empty-list<int>  $endedDaysAgo  one per segment, in chain order
 * @return array{uuid: string, token: string}
 */
function manageEndedBooking(array $endedDaysAgo, string $schema = 'tenant_luna'): array
{
    $token = ManageToken::generate();
    $uuid = (string) Str::uuid();
    $service = manageId('services', 'Haircut', $schema);
    $member = manageId('staff', 'Elena Papadaki', $schema);

    $bookingId = DB::table($schema.'.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid,
        'status' => 'completed',
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'total_minor' => 3500 * count($endedDaysAgo),
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'manage_token_hash' => $token->hash(),
    ]);

    foreach ($endedDaysAgo as $sort => $days) {
        $end = manageNow()->sub(new DateInterval(sprintf('P%dD', $days)));
        $start = $end->sub(new DateInterval('PT1H'));

        DB::table($schema.'.booking_items')->insert([
            'booking_id' => $bookingId,
            'sort' => $sort,
            'service_id' => $service,
            'staff_id' => $member,
            'start_utc' => manageInstant($start),
            'end_utc' => manageInstant($end),
            'block_start_utc' => manageInstant($start),
            'block_end_utc' => manageInstant($end),
            'price_minor' => 3500,
            'blocks' => false,
        ]);
    }

    return ['uuid' => $uuid, 'token' => $token->plain];
}

/**
 * Everything about a refusal that a caller can compare: the status and the body, byte for byte.
 *
 * @param  TestResponse<Response>  $response
 */
function manageRefusal(TestResponse $response): string
{
    $body = $response->getContent();

    assert(is_string($body));

    return $response->status().' '.$body;
}

/**
 * The body of an answer, as the map of keys it is.
 *
 * @param  TestResponse<Response>  $response
 * @return array<mixed>
 */
function manageAnswer(TestResponse $response): array
{
    $body = $response->json();

    assert(is_array($body));

    return $body;
}

/**
 * The body of a hold on luna: one haircut with Elena, on the Tuesday the demo week has her
 * working, spelled in the zone the tenant's clock speaks.
 *
 * @return array<string, mixed>
 */
function manageHoldBody(): array
{
    return [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => [['service_id' => manageId('services', 'Haircut'), 'staff_id' => manageId('staff', 'Elena Papadaki')]],
        'start' => (new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339),
    ];
}

it('answers the booking the token was handed out for with the guest view, no-store, and the general limit', function () {
    $held = manageAnswer($this->postJson(tenantUrl('luna', '/api/v1/holds'), manageHoldBody())->assertCreated());
    $uuid = $held['uuid'];
    $token = $held['manage_token'];

    assert(is_string($uuid) && is_string($token));

    $response = $this->getJson(manageUrl($uuid, $token))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-RateLimit-Limit', '300');
    $read = manageAnswer($response);

    expect(array_keys($read))->toBe([...BookingPayload::GUEST, 'items'])
        ->and($read)->toBe(array_diff_key($held, ['manage_token' => null]))
        ->and($read)->not->toHaveKey('manage_token')
        ->and($read)->not->toHaveKey('checkout_url')
        ->and($read)->not->toHaveKey('rejection_reason')
        ->and($read)->not->toHaveKey('id')
        ->and($read)->not->toHaveKey('manage_token_hash')
        ->and($read['customer_email'])->toBe('casey@example.com')
        ->and($read['customer_phone'])->toBe('+30 210 0000000');
});

it('stored the hash of the token the hold handed out, and never the token itself', function () {
    $held = manageAnswer($this->postJson(tenantUrl('luna', '/api/v1/holds'), manageHoldBody())->assertCreated());
    $token = $held['manage_token'];
    $stored = DB::table('tenant_luna.bookings')->where('uuid', $held['uuid'])->value('manage_token_hash');

    assert(is_string($token) && is_string($stored));

    expect($stored)->toBe(ManageToken::hashOf($token))
        ->and($stored)->not->toBe($token);
});

it('answers a wrong token, another booking\'s token, a nulled hash, a lapsed token and an unknown booking with one refusal, byte for byte', function () {
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([1]);
    ['uuid' => $otherUuid, 'token' => $otherToken] = manageEndedBooking([2]);
    ['uuid' => $nulledUuid, 'token' => $nulledToken] = manageEndedBooking([3]);
    ['uuid' => $lapsedUuid, 'token' => $lapsedToken] = manageEndedBooking([ManageTokenGuard::LIFETIME_DAYS + 1]);
    DB::table('tenant_luna.bookings')->where('uuid', $nulledUuid)->update(['manage_token_hash' => null]);

    $refusals = [
        'a wrong token' => manageRefusal($this->getJson(manageUrl($uuid, ManageToken::generate()->plain))),
        'another booking\'s token' => manageRefusal($this->getJson(manageUrl($uuid, $otherToken))),
        'a nulled hash' => manageRefusal($this->getJson(manageUrl($nulledUuid, $nulledToken))),
        'a lapsed token' => manageRefusal($this->getJson(manageUrl($lapsedUuid, $lapsedToken))),
        'an unknown booking' => manageRefusal($this->getJson(manageUrl((string) Str::uuid(), $token))),
        'an identifier that is no uuid' => manageRefusal($this->getJson(manageUrl('not-a-uuid-at-all', $token))),
        'the right booking of the wrong tenant' => manageRefusal($this->getJson(manageUrl($uuid, $token, 'pier'))),
    ];

    expect(array_values(array_unique($refusals)))->toHaveCount(1);

    $this->getJson(manageUrl($uuid, $token))->assertOk();
    $this->getJson(manageUrl($otherUuid, $otherToken))->assertOk();
    $this->getJson(manageUrl($uuid, ManageToken::generate()->plain))
        ->assertNotFound()
        ->assertExactJson([
            'code' => 'not_found',
            'message' => 'not_found',
            'data' => ['status' => 404, 'detail' => apiSentence('api.status.not_found')],
        ]);
});

it('reads a booking whose last segment ended a day inside the lifetime and refuses one a day outside it', function () {
    ['uuid' => $inside, 'token' => $insideToken] = manageEndedBooking([ManageTokenGuard::LIFETIME_DAYS - 1]);
    ['uuid' => $outside, 'token' => $outsideToken] = manageEndedBooking([ManageTokenGuard::LIFETIME_DAYS + 1]);

    $this->getJson(manageUrl($inside, $insideToken))->assertOk()->assertJsonPath('uuid', $inside);
    $this->getJson(manageUrl($outside, $outsideToken))->assertNotFound();
});

it('keeps the credential alive on the last day of the lifetime and kills it the moment the day after begins, half-open like every other range here', function () {
    // The helper ends the booking exactly the lifetime before the clock stands, so the deadline
    // is the clock's own instant. Moving the clock rather than the booking is what lets a
    // second name the boundary: the pinned cases either side of it are days apart and a guard
    // that lapsed a day late would pass both.
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([ManageTokenGuard::LIFETIME_DAYS]);
    $deadline = manageNow();

    app()->instance(Clock::class, new FixedClock($deadline->sub(new DateInterval('PT1S'))));
    $this->getJson(manageUrl($uuid, $token))->assertOk()->assertJsonPath('uuid', $uuid);

    app()->instance(Clock::class, new FixedClock($deadline));
    $this->getJson(manageUrl($uuid, $token))->assertNotFound();
});

it('measures the lifetime from the last segment of a chain and not from its first', function () {
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([
        ManageTokenGuard::LIFETIME_DAYS + 2,
        ManageTokenGuard::LIFETIME_DAYS - 1,
    ]);

    $this->getJson(manageUrl($uuid, $token))
        ->assertOk()
        ->assertJsonPath('uuid', $uuid)
        ->assertJsonCount(2, 'items');
});

it('answers a request carrying no token at all 400 invalid_request, whatever the booking', function (string $case) {
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([1]);

    $this->getJson(match ($case) {
        'no parameter' => manageUrl($uuid),
        'an empty parameter' => manageUrl($uuid, ''),
        'a booking nobody has' => manageUrl((string) Str::uuid()),
        default => throw new LogicException(sprintf('The dataset names three cases, not "%s".', $case)),
    })
        ->assertStatus(400)
        ->assertExactJson([
            'code' => 'invalid_request',
            'message' => 'invalid_request',
            'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL)],
        ]);

    $this->getJson(manageUrl($uuid, $token))->assertOk();
})->with(['no parameter', 'an empty parameter', 'a booking nobody has']);

it('issues its reads outside any transaction and leaves the connection\'s search path untouched', function () {
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([1]);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->getJson(manageUrl($uuid, $token))->assertOk();

    expect($statements)->not->toBeEmpty()
        ->and(array_values(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(set|begin|commit|rollback|savepoint)\b/i', $sql) === 1)))->toBe([])
        ->and(array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'current_schema'))))->toBe([])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

it('is permissively cross-origin on the route and on its preflight, so the embed on a tenant\'s own site can read a booking', function () {
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([1]);

    $this->getJson(manageUrl($uuid, $token), ['Origin' => 'https://www.luna-hair.example'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');

    $this->call('OPTIONS', manageUrl($uuid), server: [
        'HTTP_ORIGIN' => 'https://www.luna-hair.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'GET')
        ->assertHeader('Access-Control-Allow-Headers', 'content-type');
});

/**
 * A throwaway route under the API prefix behind the same guard, standing for a write route: a
 * POST carries its token in the body, and this is where that half of the guard is exercised.
 */
function manageWriteRoute(): void
{
    $read = Route::getRoutes()->getByName('api.booking');

    assert($read instanceof RegisteredRoute);

    Route::domain((string) $read->getDomain())
        ->middleware([ResolveTenant::class, RequiresManageToken::NAME])
        ->post('/api/v1/_manage/{uuid}', fn (ManagedBooking $managed): JsonResponse => new JsonResponse(['uuid' => $managed->get()->uuid]));
}

it('reads a POST\'s token from its body and not from its query string, and binds the booking for the route behind it', function () {
    ['uuid' => $uuid, 'token' => $token] = manageEndedBooking([1]);
    manageWriteRoute();
    $url = tenantUrl('luna', '/api/v1/_manage/'.$uuid);

    $this->postJson($url, ['token' => $token])->assertOk()->assertJsonPath('uuid', $uuid);
    $this->postJson($url, ['token' => ManageToken::generate()->plain])->assertNotFound();
    $this->postJson($url.'?token='.$token)
        ->assertStatus(400)
        ->assertJsonPath('code', 'invalid_request')
        ->assertJsonPath('data.detail', apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL));
});
