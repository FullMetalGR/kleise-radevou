<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\HoldBooking;
use App\Domain\Chain\Chain;
use App\Http\BookingPayload;
use App\Http\ErrorEnvelope;
use App\Http\Middleware\ResolveTenant;
use App\Http\Requests\Instant;
use App\Infrastructure\Db\BookingReader;
use App\Providers\AppServiceProvider;
use App\Tenancy\Tenant;
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
 * POST /api/v1/holds on the wire, over the demo tenants' real rows: what a hold answers, what
 * the customer is shown of it and what is withheld, every refusal by status and code, the
 * limit, and the cross-origin properties the read routes already carry.
 *
 * Not wrapped in a transaction: the route enters the transaction runner, which refuses to open
 * inside another. The central tables are truncated before each test and the demo schemas
 * dropped and the central rows deleted after.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00 and Nikos 10:00 to 19:00. pier is in Europe/London, and its
 * services run on its implicit member, which keeps the default week of 09:00 to 17:00. The
 * clock the planner reads stands on the Monday before; the expiries are the database's own.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    Checkpoint::observe(null);
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function holdsUrl(string $slug = 'luna'): string
{
    return tenantUrl($slug, '/api/v1/holds');
}

function holdsId(string $schema, string $table, string $name): int
{
    $id = DB::table($schema.'.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * An instant as the booking app sends one: RFC 3339, in the zone the tenant's clock speaks.
 */
function holdsAt(string $text, string $zone = 'Europe/Athens'): string
{
    return (new DateTimeImmutable($text, new DateTimeZone($zone)))->format(DateTimeImmutable::RFC3339);
}

/**
 * The body of a well-formed hold: the customer, the chain as service and staff names on the
 * tenant's schema, and the start.
 *
 * @param  list<array{string, string|null}>  $items  service name and staff name or null
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function holdsBody(array $items, string $start, array $overrides = [], string $schema = 'tenant_luna'): array
{
    return [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => array_map(fn (array $item): array => [
            'service_id' => holdsId($schema, 'services', $item[0]),
            'staff_id' => $item[1] === null ? null : holdsId($schema, 'staff', $item[1]),
        ], $items),
        'start' => $start,
        ...$overrides,
    ];
}

/**
 * The body of an answer, as the map of keys it is.
 *
 * @param  TestResponse<Response>  $response
 * @return array<mixed>
 */
function holdsAnswer(TestResponse $response): array
{
    $body = $response->json();

    assert(is_array($body));

    return $body;
}

/**
 * The items of an answer, each as the map of keys it is.
 *
 * @param  array<mixed>  $body
 * @return list<array<mixed>>
 */
function holdsItems(array $body): array
{
    $items = $body['items'] ?? null;

    assert(is_array($items));

    $narrowed = [];

    foreach ($items as $item) {
        assert(is_array($item));

        $narrowed[] = $item;
    }

    return $narrowed;
}

/**
 * A value of a row or an answer that the schema says is text.
 */
function holdsText(mixed $value): string
{
    assert(is_string($value));

    return $value;
}

/**
 * Every bookings row of a schema as text, the way the row stands. What a test reads to prove
 * that a value it was handed is not in the table.
 */
function holdsBookingsAsText(string $schema = 'tenant_luna'): string
{
    $rows = DB::selectOne(sprintf("select coalesce(string_agg(row_to_json(b)::text, ' '), '') as text from %s.bookings b", $schema));

    assert($rows instanceof stdClass && is_string($rows->text));

    return $rows->text;
}

/**
 * A booking of some other customer, written straight to the rows on a second connection so it
 * commits while the hold under test is inside its transaction, holding its mutex rows. Not a
 * hold: a confirmed booking blocks whatever the sweeper does.
 */
function holdsRivalBooking(int $staffId, string $start, string $end): void
{
    config(['database.connections.holds_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('holds_rival');

    $bookingId = $rival->table('tenant_luna.bookings')->insertGetId([...bookingRow(), 'uuid' => (string) Str::uuid(), 'customer_name' => 'Robin Rival']);
    $rival->table('tenant_luna.booking_items')->insert(itemRow($bookingId, $staffId, $start, $end));

    DB::purge('holds_rival');
}

it('answers a valid hold 201 with the guest view of the booking, the manage token in plaintext beside it, and no-store', function () {
    $elena = holdsId('tenant_luna', 'staff', 'Elena Papadaki');
    $haircut = holdsId('tenant_luna', 'services', 'Haircut');

    $response = $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Elena Papadaki']], holdsAt('2026-11-03 09:00')));

    $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $body = holdsAnswer($response);
    $row = DB::table('tenant_luna.bookings')->sole();
    $token = holdsText($body['manage_token'] ?? null);
    $hash = holdsText($row->manage_token_hash);
    $expiresAt = holdsText(DB::table('tenant_luna.bookings')->selectRaw('hold_expires_at::text as at')->value('at'));

    expect(array_keys($body))->toBe([...BookingPayload::GUEST, 'items', 'manage_token'])
        ->and(array_keys(holdsItems($body)[0]))->toBe(['sort', 'service_id', 'service_name', 'staff_id', 'staff_name', 'start', 'end', 'price_minor'])
        ->and($body['uuid'])->toBe($row->uuid)
        ->and($body['status'])->toBe('pending')
        ->and($body['hold_class'])->toBe('checkout')
        ->and($body['customer_name'])->toBe('Casey Customer')
        ->and($body['customer_email'])->toBe('casey@example.com')
        ->and($body['customer_phone'])->toBe('+30 210 0000000')
        ->and($body['total_minor'])->toBe(3500)
        ->and($body['currency'])->toBe('EUR')
        ->and($body['items'])->toBe([[
            'sort' => 0,
            'service_id' => $haircut,
            'service_name' => 'Haircut',
            'staff_id' => $elena,
            'staff_name' => 'Elena Papadaki',
            'start' => '2026-11-03T07:00:00+00:00',
            'end' => '2026-11-03T07:45:00+00:00',
            'price_minor' => 3500,
        ]])
        ->and($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and(hash('sha256', $token))->toBe($hash)
        ->and(str_contains(holdsBookingsAsText(), $token))->toBeFalse()
        ->and(str_contains(holdsBookingsAsText(), $hash))->toBeTrue()
        ->and($body['hold_expires_at'])->toBe((new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339));
});

it('holds a free start off the tenant\'s start interval, which the booking app is not offered: the interval shapes the offer and not the write', function () {
    $offered = $this->getJson(tenantUrl('luna', '/api/v1/availability?'.http_build_query([
        'items' => (string) json_encode([['service_id' => holdsId('tenant_luna', 'services', 'Haircut'), 'staff_id' => holdsId('tenant_luna', 'staff', 'Elena Papadaki')]]),
        'from' => '2026-11-03',
        'to' => '2026-11-04',
    ])))->assertOk()->json('2026-11-03');

    expect(DB::table('tenant_luna.settings')->where('id', 1)->value('start_interval_min'))->toBe(15)
        ->and($offered)->toContain('2026-11-03T08:00:00+00:00')
        ->and($offered)->not->toContain('2026-11-03T08:05:00+00:00');

    $response = $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Elena Papadaki']], holdsAt('2026-11-03 10:05')))->assertCreated();

    expect(holdsItems(holdsAnswer($response))[0]['start'])->toBe('2026-11-03T08:05:00+00:00');
});

it('names the service and the staff member on every item, and names no member where the service runs on the tenant\'s implicit one', function () {
    $luna = $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Nikos Georgiou']], holdsAt('2026-11-03 10:00')))->assertCreated();
    $pier = $this->postJson(holdsUrl('pier'), holdsBody([['Initial assessment', null]], holdsAt('2026-11-03 10:00', 'Europe/London'), schema: 'tenant_pier'))->assertCreated();

    $luna->assertJsonPath('items.0.service_name', 'Haircut')->assertJsonPath('items.0.staff_name', 'Nikos Georgiou');
    $pier->assertJsonPath('items.0.service_name', 'Initial assessment')
        ->assertJsonPath('items.0.staff_name', null)
        ->assertJsonPath('items.0.staff_id', implicitStaffId('tenant_pier'))
        ->assertJsonPath('currency', 'GBP');
});

it('answers a chain the tenant cannot start where it was asked 409 unavailable with its segment, writing nothing', function () {
    $outOfHours = $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Elena Papadaki']], holdsAt('2026-11-03 08:00')));
    $past = $this->postJson(holdsUrl(), holdsBody([['Haircut', null]], holdsAt('2026-11-01 11:00')));
    $secondSegment = $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Nikos Georgiou'], ['Beard trim', 'Nikos Georgiou']], holdsAt('2026-12-04 13:15')));

    $outOfHours->assertStatus(409)->assertExactJson([
        'code' => 'unavailable',
        'message' => 'unavailable',
        'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::UNAVAILABLE_DETAIL), 'segment' => 0],
    ]);
    $past->assertStatus(409)->assertJsonPath('code', 'unavailable')->assertJsonPath('data.segment', 0);
    $secondSegment->assertStatus(409)->assertJsonPath('code', 'unavailable')->assertJsonPath('data.segment', 1);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0)
        ->and(DB::table('tenant_luna.booking_items')->count())->toBe(0);
});

it('answers a slot taken between the advisory read and the lock 409 overlap with its segment, writing nothing of its own', function () {
    $elena = holdsId('tenant_luna', 'staff', 'Elena Papadaki');

    Checkpoint::observe(function (string $name) use ($elena): void {
        if ($name === HoldBooking::AFTER_LOCK) {
            holdsRivalBooking($elena, '2026-11-03 07:30:00+00', '2026-11-03 08:15:00+00');
        }
    });

    $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Elena Papadaki']], holdsAt('2026-11-03 09:00')))
        ->assertStatus(409)
        ->assertExactJson([
            'code' => 'overlap',
            'message' => 'overlap',
            'data' => ['status' => 409, 'detail' => apiSentence(ErrorEnvelope::OVERLAP_DETAIL), 'segment' => 0],
        ]);

    expect(DB::table('tenant_luna.bookings')->pluck('customer_name')->all())->toBe(['Robin Rival'])
        ->and(DB::table('tenant_luna.booking_items')->count())->toBe(1);
});

it('answers a body the boundary refuses 400 invalid_request with the sentence for the field that failed', function (array $body, string $detail) {
    $this->postJson(holdsUrl(), $body)
        ->assertStatus(400)
        ->assertExactJson(['code' => 'invalid_request', 'message' => 'invalid_request', 'data' => ['status' => 400, 'detail' => $detail]]);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0);
})->with([
    'no name' => [fn (): array => array_diff_key(holdsBody([['Haircut', null]], holdsAt('2026-11-03 09:00')), ['customer_name' => null]), fn (): string => apiSentence('api.validation.customer_name.required')],
    'a name of spaces' => [fn (): array => holdsBody([['Haircut', null]], holdsAt('2026-11-03 09:00'), ['customer_name' => '   ']), fn (): string => apiSentence('api.validation.customer_name.required')],
    'an address that is not one' => [fn (): array => holdsBody([['Haircut', null]], holdsAt('2026-11-03 09:00'), ['customer_email' => 'casey at example.com']), fn (): string => apiSentence('api.validation.customer_email.email')],
    'no staff_id key on the first item' => [fn (): array => holdsBody([['Haircut', null]], holdsAt('2026-11-03 09:00'), ['items' => [['service_id' => holdsId('tenant_luna', 'services', 'Haircut')]]]), fn (): string => apiSentence('api.validation.item.no_staff_id', ['n' => '1'])],
    'a start that is not RFC 3339' => [fn (): array => holdsBody([['Haircut', null]], '2026-11-03 09:00:00'), fn (): string => apiSentence(Instant::SENTENCE)],
    'a start on a date that does not exist' => [fn (): array => holdsBody([['Haircut', null]], '2026-11-31T09:00:00+02:00'), fn (): string => apiSentence(Instant::SENTENCE)],
    'one item more than the cap' => [fn (): array => holdsBody(array_fill(0, Chain::MAX_SEGMENTS + 1, ['Haircut', null]), holdsAt('2026-11-03 09:00')), fn (): string => apiChoice('api.validation.items.max', Chain::MAX_SEGMENTS)],
    'no items at all' => [fn (): array => holdsBody([['Haircut', null]], holdsAt('2026-11-03 09:00'), ['items' => []]), fn (): string => apiSentence('api.validation.items.required')],
]);

it('answers a service the catalog does not offer 404, the inactive one exactly as the unknown one, and a pick who does not perform the service 400', function () {
    $unknown = $this->postJson(holdsUrl(), holdsBody([['Haircut', null]], holdsAt('2026-11-03 09:00'), ['items' => [['service_id' => 424242, 'staff_id' => null]]]));
    $inactive = $this->postJson(holdsUrl(), holdsBody([['Perm', null]], holdsAt('2026-11-03 09:00')));
    $wrongMember = $this->postJson(holdsUrl(), holdsBody([['Beard trim', 'Elena Papadaki']], holdsAt('2026-11-03 11:00')));

    $expected = ['code' => 'not_found', 'message' => 'not_found', 'data' => ['status' => 404, 'detail' => apiSentence(ErrorEnvelope::SERVICE_GONE_DETAIL)]];

    $unknown->assertNotFound()->assertExactJson($expected);
    $inactive->assertNotFound()->assertExactJson($expected);
    $wrongMember->assertStatus(400)->assertExactJson([
        'code' => 'invalid_request',
        'message' => 'invalid_request',
        'data' => ['status' => 400, 'detail' => apiSentence(ErrorEnvelope::STAFF_NOT_ELIGIBLE_DETAIL)],
    ]);

    expect(DB::table('tenant_luna.bookings')->count())->toBe(0);
});

it('answers a service the owner approves by hand 201 awaiting_approval under an approval hold', function () {
    $this->postJson(holdsUrl(), holdsBody([['Bridal trial', 'Elena Papadaki']], holdsAt('2026-11-03 09:00')))
        ->assertCreated()
        ->assertJsonPath('status', 'awaiting_approval')
        ->assertJsonPath('hold_class', 'approval')
        ->assertJsonPath('total_minor', 15000);

    $row = DB::table('tenant_luna.bookings')->selectRaw("hold_expires_at = created_at + interval '48 hours' as after_approval_ttl, requires_approval")->sole();

    expect($row->after_approval_ttl)->toBeTrue()
        ->and($row->requires_approval)->toBeTrue();
});

it('classifies every column of the live bookings and booking_items tables, so a column added later reaches nobody until somebody decides', function () {
    $bookings = [...BookingPayload::GUEST, ...BookingPayload::VIEWER, ...BookingPayload::MANAGE_ONLY, ...BookingPayload::WITHHELD];
    $items = [...BookingPayload::ITEM_GUEST, ...BookingPayload::ITEM_MANAGE_ONLY, ...BookingPayload::ITEM_WITHHELD];
    $liveBookings = liveColumns('tenant_luna', 'bookings');
    $liveItems = liveColumns('tenant_luna', 'booking_items');
    sort($bookings);
    sort($items);
    sort($liveBookings);
    sort($liveItems);

    expect($liveBookings)->not->toBeEmpty()
        ->and($bookings)->toBe($liveBookings)
        ->and($items)->toBe($liveItems);
});

it('shows a guest their own email and phone and shows nobody the serial id or the token hash', function () {
    $body = holdsAnswer($this->postJson(holdsUrl(), holdsBody([['Haircut', null]], holdsAt('2026-11-03 10:00')))->assertCreated());
    $item = holdsItems($body)[0];

    expect($body)->toHaveKeys(['customer_email', 'customer_phone'])
        ->and($body)->not->toHaveKey('id')
        ->and($body)->not->toHaveKey('manage_token_hash')
        ->and($body)->not->toHaveKey('checkout_url')
        ->and($body)->not->toHaveKey('payment_mode')
        ->and($item)->not->toHaveKey('id')
        ->and($item)->not->toHaveKey('booking_id')
        ->and($item)->not->toHaveKey('block_start_utc')
        ->and($item)->not->toHaveKey('blocks');
});

it('takes ten holds a minute from one address on one tenant and refuses the eleventh, while the same address still books at the other tenant', function () {
    $starts = [];

    for ($slot = 0; $slot < AppServiceProvider::HOLD_REQUESTS_PER_MINUTE; $slot++) {
        $starts[] = $this->postJson(holdsUrl(), holdsBody([['Beard trim', 'Nikos Georgiou']], holdsAt(sprintf('2026-11-03 %02d:%02d', 10 + intdiv($slot * 20, 60), $slot * 20 % 60))))->status();
    }

    expect(array_unique($starts))->toBe([201]);

    $this->postJson(holdsUrl(), holdsBody([['Haircut', 'Elena Papadaki']], holdsAt('2026-11-03 09:00')))
        ->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', '10')
        ->assertHeader('Retry-After')
        ->assertExactJson(['code' => 'rate_limited', 'message' => 'rate_limited', 'data' => ['status' => 429, 'detail' => apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)]]);

    $this->postJson(holdsUrl('pier'), holdsBody([['Initial assessment', null]], holdsAt('2026-11-03 10:00', 'Europe/London'), schema: 'tenant_pier'))->assertCreated();

    expect(DB::table('tenant_luna.bookings')->count())->toBe(AppServiceProvider::HOLD_REQUESTS_PER_MINUTE);
});

it('is permissively cross-origin on the route and on its preflight, so the embed on a tenant\'s own site can hold a slot', function () {
    $this->postJson(holdsUrl(), holdsBody([['Haircut', null]], holdsAt('2026-11-03 10:00')), ['Origin' => 'https://www.luna-hair.example'])
        ->assertCreated()
        ->assertHeader('Access-Control-Allow-Origin', '*');

    $this->call('OPTIONS', holdsUrl(), server: [
        'HTTP_ORIGIN' => 'https://www.luna-hair.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'POST')
        ->assertHeader('Access-Control-Allow-Headers', 'content-type');
});

it('takes a hold carrying no CSRF token, because the public API is not a cookie-authenticated form', function () {
    // The token middleware stands down under the test runner, so the environment is moved off
    // testing for this one test: it is the only way to see the exception list do its work, and
    // a hold from an embed on a tenant's own site has no token and no session to take one from.
    $this->app->detectEnvironment(fn (): string => 'local');

    $holds = Route::getRoutes()->getByName('api.holds');

    assert($holds instanceof RegisteredRoute);

    Route::domain((string) $holds->getDomain())->middleware(['web', ResolveTenant::class])
        ->post('/_form', fn (): JsonResponse => new JsonResponse(['posted' => true]));

    $this->postJson(tenantUrl('luna', '/_form'))->assertStatus(419);
    $this->postJson(holdsUrl(), holdsBody([['Haircut', null]], holdsAt('2026-11-03 10:00')))->assertCreated();
});

it('holds a chain of two segments as one booking, its items in chain order on the one member the customer asked to keep, and no phone where none was given', function () {
    $body = holdsAnswer($this->postJson(holdsUrl(), holdsBody(
        [['Haircut', null], ['Beard trim', null]],
        holdsAt('2026-11-03 10:00'),
        ['same_staff' => true, 'customer_phone' => ''],
    ))->assertCreated());
    $items = holdsItems($body);

    expect($body['customer_phone'])->toBeNull()
        ->and($body['total_minor'])->toBe(5000)
        ->and(array_column($items, 'sort'))->toBe([0, 1])
        ->and(array_column($items, 'service_name'))->toBe(['Haircut', 'Beard trim'])
        ->and(array_values(array_unique(array_map(holdsText(...), array_column($items, 'staff_name')))))->toBe(['Nikos Georgiou'])
        ->and(array_column($items, 'start'))->toBe(['2026-11-03T08:00:00+00:00', '2026-11-03T08:45:00+00:00'])
        ->and(array_column($items, 'price_minor'))->toBe([3500, 1500]);
});

it('reads a booking back by its public identifier alone, on the schema of the tenant it was given, and answers nothing for an identifier that names no booking of theirs', function () {
    $uuid = holdsText(holdsAnswer($this->postJson(holdsUrl(), holdsBody([['Haircut', null]], holdsAt('2026-11-03 10:00')))->assertCreated())['uuid'] ?? null);
    $reader = app(BookingReader::class);
    $luna = Tenant::query()->where('slug', 'luna')->sole();
    $pier = Tenant::query()->where('slug', 'pier')->sole();

    expect($reader->snapshot($luna, $uuid)?->uuid)->toBe($uuid)
        ->and($reader->snapshot($luna, $uuid)?->items)->toHaveCount(1)
        ->and($reader->snapshot($pier, $uuid))->toBeNull()
        ->and($reader->snapshot($luna, '7b7f0e5a-0000-4000-8000-000000000009'))->toBeNull()
        ->and($reader->snapshot($luna, 'not-a-uuid-at-all'))->toBeNull();
});
