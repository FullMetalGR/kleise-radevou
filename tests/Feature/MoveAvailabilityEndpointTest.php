<?php

use App\Application\Clock;
use App\Application\Dto\DayWindow;
use App\Domain\Booking\ManageToken;
use App\Http\ErrorEnvelope;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FixedClock;
use Tests\Support\StartInterval;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(DatabaseTruncation::class);

/*
 * GET /api/v1/bookings/{uuid}/availability on the wire, over luna's real rows: the nudge the
 * public read cannot offer, the chain read from the rows, the masks rebuilt rather than
 * subtracted, the cache left as it was found, every refusal, the chain the catalog can no
 * longer place, the status left unjudged, and the limit, the header, CORS and the connection.
 *
 * Not wrapped in a transaction: the holds, the confirms and the one move enter the transaction
 * runner, which refuses to open inside another. The central tables are truncated before each
 * test and the demo schemas dropped after, with kolkata's, the one tenant a case provisions. The
 * mask cache is the null store unless a test binds an array store to look inside.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-09 is a Monday: Elena works it 09:00 to
 * 13:00 and 14:00 to 18:00 and Nikos does not; Nikos works Tuesday to Saturday 10:00 to 19:00.
 * The Haircut is 45 minutes with no buffers and the grid is five minutes. The clock stands on
 * the Monday before, outside every reschedule window here. A test whose subject is that grid
 * sets luna's start interval to 5, so the answer is every free step; the others read the
 * default of 15. Athens is whole hours from UTC, so the case proving the interval is read on
 * the tenant's wall clock runs on kolkata, in Asia/Kolkata, UTC+05:30.
 */

beforeEach(function () {
    dropDemoSchemas();
    DB::statement('drop schema if exists tenant_kolkata cascade');
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    marClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    dropDemoSchemas();
    DB::statement('drop schema if exists tenant_kolkata cascade');
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function marClockAt(string $utc): void
{
    app()->instance(Clock::class, FixedClock::at($utc));
}

function marId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

function marStart(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens')))->format(DateTimeImmutable::RFC3339);
}

/**
 * A hold taken over the wire, each item a service and a member or null, the way the booking app
 * takes one; confirmed through the manage token when asked.
 *
 * @param  list<array{string, string|null}>  $items  service and member names
 * @return array{uuid: string, token: string}
 */
function marBook(TestCase $test, array $items, string $start, bool $confirm = true, string $name = 'Casey Customer'): array
{
    $held = $test->postJson(tenantUrl('luna', '/api/v1/holds'), [
        'customer_name' => $name,
        'customer_email' => 'casey@example.com',
        'customer_phone' => '+30 210 0000000',
        'items' => array_map(fn (array $item): array => ['service_id' => marId('services', $item[0]), 'staff_id' => $item[1] === null ? null : marId('staff', $item[1])], $items),
        'start' => marStart($start),
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    if ($confirm) {
        $test->postJson(tenantUrl('luna', '/api/v1/bookings/'.$held['uuid'].'/confirm'), ['token' => $held['manage_token']])->assertOk();
    }

    return ['uuid' => $held['uuid'], 'token' => $held['manage_token']];
}

/**
 * @param  array<string, mixed>  $query
 */
function marUrl(string $uuid, array $query): string
{
    return tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/availability?'.http_build_query($query));
}

/**
 * The per-booking read over a window, the token in the query string, where a GET carries it.
 *
 * @return TestResponse<Response>
 */
function marRead(TestCase $test, string $uuid, ?string $token, string $from = '2026-11-09', string $to = '2026-11-16'): TestResponse
{
    return $test->getJson(marUrl($uuid, array_filter(['token' => $token, 'from' => $from, 'to' => $to], fn (?string $value): bool => $value !== null)));
}

/**
 * The public read of a chain, each item a service and a member or null, over a window.
 *
 * @param  list<array{string, string|null}>  $items
 * @return TestResponse<Response>
 */
function marPublic(TestCase $test, array $items, string $from = '2026-11-09', string $to = '2026-11-16'): TestResponse
{
    return $test->getJson(tenantUrl('luna', '/api/v1/availability?'.http_build_query([
        'items' => (string) json_encode(array_map(fn (array $item): array => ['service_id' => marId('services', $item[0]), 'staff_id' => $item[1] === null ? null : marId('staff', $item[1])], $items)),
        'from' => $from,
        'to' => $to,
    ])));
}

/**
 * Every Athens reading from one to another inclusive on a day, five minutes apart unless told
 * otherwise, spelled as the wire spells a start: RFC 3339 in UTC.
 *
 * @return list<string>
 */
function marEvery(string $day, string $from, string $to, int $minutes = 5): array
{
    $local = new DateTimeZone('Europe/Athens');
    $end = new DateTimeImmutable($day.' '.$to, $local);
    $starts = [];

    for ($at = new DateTimeImmutable($day.' '.$from, $local); $at <= $end; $at = $at->add(new DateInterval('PT'.$minutes.'M'))) {
        $starts[] = $at->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    }

    return $starts;
}

function marUtc(string $athens): string
{
    return (new DateTimeImmutable($athens, new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
function marDays(TestResponse $response): array
{
    $days = $response->assertOk()->json();

    assert(is_array($days));

    /** @var array<string, list<string>> $days */
    return $days;
}

/**
 * @return array{code: string, message: string, data: array{status: int, detail: string}}
 */
function marEnvelope(string $code, int $status, string $detail): array
{
    return ['code' => $code, 'message' => $code, 'data' => ['status' => $status, 'detail' => $detail]];
}

it('offers the thirty-minute nudge on the same member that the public read for that chain cannot, leaves the current start out, and the move takes the start it offered', function () {
    StartInterval::set('tenant_luna', 5);
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');

    $moves = marDays(marRead($this, $uuid, $token));
    $public = marDays(marPublic($this, [['Haircut', 'Elena Papadaki']]));

    expect($moves['2026-11-09'])->toBe([...array_slice(marEvery('2026-11-09', '09:00', '12:15'), 1), ...marEvery('2026-11-09', '14:00', '17:15')])
        ->and($moves['2026-11-09'])->toContain(marUtc('2026-11-09 09:30'))
        ->and($moves['2026-11-09'])->not->toContain(marUtc('2026-11-09 09:00'))
        ->and($public['2026-11-09'])->not->toContain(marUtc('2026-11-09 09:30'))
        ->and(array_diff_key($moves, ['2026-11-09' => true]))->toBe(array_diff_key($public, ['2026-11-09' => true]));

    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), ['token' => $token, 'start' => marStart('2026-11-09 09:30')])->assertOk();
});

it('answers only the starts on the tenant\'s start interval, a quarter hour by default, as the public read does, the current start still left out', function () {
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');

    $moves = marDays(marRead($this, $uuid, $token));
    $public = marDays(marPublic($this, [['Haircut', 'Elena Papadaki']]));

    expect($moves['2026-11-09'])->toBe([...marEvery('2026-11-09', '09:15', '12:15', 15), ...marEvery('2026-11-09', '14:00', '17:15', 15)])
        ->and($moves['2026-11-10'])->toBe([...marEvery('2026-11-10', '09:00', '12:15', 15), ...marEvery('2026-11-10', '14:00', '17:15', 15)])
        ->and(array_diff_key($moves, ['2026-11-09' => true]))->toBe(array_diff_key($public, ['2026-11-09' => true]));

    StartInterval::set('tenant_luna', 5);

    expect(marDays(marRead($this, $uuid, $token))['2026-11-09'])->toBe([...marEvery('2026-11-09', '09:05', '12:15'), ...marEvery('2026-11-09', '14:00', '17:15')]);
});

it('thins on the tenant\'s wall clock and not on UTC: a booking in Asia/Kolkata at 60 is offered its day\'s other whole hours, which the wire spells at half past', function () {
    provisionTenant('kolkata', ['timezone' => 'Asia/Kolkata']);
    StartInterval::set('tenant_kolkata', 60);
    marClockAt('2026-04-05 12:00:00');
    $ann = DB::table('tenant_kolkata.staff')->insertGetId(['name' => 'Ann', 'status' => 'active']);
    $cut = DB::table('tenant_kolkata.services')->insertGetId(serviceRow('Cut', ['duration_min' => 30]));
    DB::table('tenant_kolkata.service_staff')->insert(['service_id' => $cut, 'staff_id' => $ann]);
    WeeklyHours::add('tenant_kolkata', $ann, 1, '09:00:00', '12:00:00');

    $held = $this->postJson(tenantUrl('kolkata', '/api/v1/holds'), [
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'items' => [['service_id' => $cut, 'staff_id' => $ann]],
        'start' => '2026-04-06T03:30:00+00:00',
    ])->assertCreated()->json();

    assert(is_array($held) && is_string($held['uuid']) && is_string($held['manage_token']));

    // Kolkata is UTC+05:30, so its whole hours are half past on the wire: 09:00 is the booking's
    // own start and left out, and a 30-minute cut cannot start at 12:00, when Ann stops. A filter
    // reading the minute in UTC would offer 09:30, 10:30 and 11:30 instead.
    $days = marDays($this->getJson(tenantUrl('kolkata', '/api/v1/bookings/'.$held['uuid'].'/availability?'.http_build_query([
        'token' => $held['manage_token'],
        'from' => '2026-04-06',
        'to' => '2026-04-07',
    ]))));

    expect($days)->toBe(['2026-04-06' => ['2026-04-06T04:30:00+00:00', '2026-04-06T05:30:00+00:00']]);
});

it('reads the chain from the rows: a two-segment booking on two members answers as the public read with both pinned on every day it does not stand on, and a booking held with no preference as pinned to the member the rows name', function () {
    StartInterval::set('tenant_luna', 5);
    ['uuid' => $pair,'token' => $pairToken] = marBook($this, [['Haircut', 'Elena Papadaki'], ['Beard trim', 'Nikos Georgiou']], '2026-11-10 10:00', confirm: false);
    ['uuid' => $any, 'token' => $anyToken] = marBook($this, [['Haircut', null]], '2026-11-11 11:00', confirm: false);
    $member = DB::table('tenant_luna.booking_items')->join('tenant_luna.bookings', 'bookings.id', '=', 'booking_items.booking_id')->where('bookings.uuid', $any)->value('booking_items.staff_id');
    $name = DB::table('tenant_luna.staff')->where('id', $member)->value('name');

    assert(is_string($name));

    $pairMoves = marDays(marRead($this, $pair, $pairToken));
    $pairPublic = marDays(marPublic($this, [['Haircut', 'Elena Papadaki'], ['Beard trim', 'Nikos Georgiou']]));
    $anyMoves = marDays(marRead($this, $any, $anyToken));
    $anyPinned = marDays(marPublic($this, [['Haircut', $name]]));
    $anyOpen = marDays(marPublic($this, [['Haircut', null]]));
    $standing = ['2026-11-10' => true, '2026-11-11' => true];

    expect(json_encode(array_diff_key($pairMoves, $standing)))->toBe(json_encode(array_diff_key($pairPublic, $standing)))
        ->and(json_encode(array_diff_key($anyMoves, $standing)))->toBe(json_encode(array_diff_key($anyPinned, $standing)))
        ->and(array_diff_key($anyMoves, $standing))->not->toBe(array_diff_key($anyOpen, $standing));
});

it('rebuilds the booking\'s own staff-day from the rows: a blackout written over its range since the sale and a buffer widened since each close the starts they close, and a range another customer holds is never offered', function () {
    StartInterval::set('tenant_luna', 5);
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 11:00', name: 'Another Customer');
    $afternoon = marEvery('2026-11-09', '14:00', '17:15');

    expect(marDays(marRead($this, $uuid, $token))['2026-11-09'])->toBe([
        ...array_slice(marEvery('2026-11-09', '09:00', '10:15'), 1),
        ...marEvery('2026-11-09', '11:45', '12:15'),
        ...$afternoon,
    ]);

    $blackout = DB::table('tenant_luna.blackouts')->insertGetId(['staff_id' => marId('staff', 'Elena Papadaki'), 'date' => '2026-11-09', 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'reason' => 'Late start']);

    expect(marDays(marRead($this, $uuid, $token))['2026-11-09'])->toBe([
        ...marEvery('2026-11-09', '10:00', '10:15'),
        ...marEvery('2026-11-09', '11:45', '12:15'),
        ...$afternoon,
    ]);

    DB::table('tenant_luna.blackouts')->where('id', $blackout)->delete();
    DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['buffer_after_min' => 30]);

    expect(marDays(marRead($this, $uuid, $token))['2026-11-09'])->toBe([
        ...array_slice(marEvery('2026-11-09', '09:00', '09:45'), 1),
        ...marEvery('2026-11-09', '11:45', '12:15'),
        ...$afternoon,
    ]);
});

/**
 * One GET with the query log captured: the response and the statements it ran, bindings and all.
 *
 * @return array{TestResponse<Response>, list<array{query: string, bindings: array<int, mixed>}>}
 */
function marLogged(TestCase $test, string $url): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $test->getJson($url)->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();
    DB::flushQueryLog();

    /** @var list<array{query: string, bindings: array<int, mixed>}> $log */
    return [$response, $log];
}

/**
 * The items statements of a log: the ones that read blocking items as a range over block_span.
 *
 * @param  list<array{query: string, bindings: array<int, mixed>}>  $log
 * @return list<array{query: string, bindings: array<int, mixed>}>
 */
function marItemsStatements(array $log): array
{
    return array_values(array_filter($log, fn (array $entry): bool => str_contains($entry['query'], 'i.block_span &&')));
}

it('leaves the cache as it found it: the rebuilt staff-day is stored nowhere and read past the store, every other staff-day is read through it, and a public read after answers the cold bytes', function () {
    $array = new ArrayStore;
    $store = new CacheRepository($array);
    app()->instance(StaffDayMaskCache::class, new StaffDayMaskCache($store));
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    $elena = marId('staff', 'Elena Papadaki');
    $monday = ':'.$elena.':2026-11-09:';
    $store->flush();

    // Cold: the move read stores every staff-day it read through the store, and not Monday.
    marRead($this, $uuid, $token)->assertOk();
    $keys = array_keys($array->all());

    expect(array_filter($keys, fn (int|string $key): bool => str_contains((string) $key, $monday)))->toBe([])
        ->and(array_filter($keys, fn (int|string $key): bool => str_contains((string) $key, ':'.$elena.':2026-11-10:')))->not->toBe([]);

    // Warm: the public read stores Monday as the rows say, with the booking in it, and the
    // move read after it neither reads that entry nor replaces it; its one items statement
    // names Elena's Monday and nothing else, with the booking left out by its identifier.
    $public = marPublic($this, [['Haircut', 'Elena Papadaki']])->assertOk()->getContent();
    $stored = $array->all();
    [, $log] = marLogged($this, marUrl($uuid, ['token' => $token, 'from' => '2026-11-09', 'to' => '2026-11-16']));
    $items = marItemsStatements($log);

    expect($array->all())->toBe($stored)
        ->and($items)->toHaveCount(1)
        ->and($items[0]['bindings'])->toBe(['{'.$elena.'}', '2026-11-08 22:00:00.000000+00:00', '2026-11-09 22:00:00.000000+00:00', $uuid]);

    $warm = marPublic($this, [['Haircut', 'Elena Papadaki']])->assertOk()->getContent();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    $cold = marPublic($this, [['Haircut', 'Elena Papadaki']])->assertOk()->getContent();

    expect($warm)->toBe($cold)
        ->and($public)->toBe($cold);
});

it('answers no token as a malformed request, and a wrong token, another booking\'s token, a nulled hash, a lapsed token and an unknown booking as one 404, byte for byte', function () {
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    ['uuid' => $other, 'token' => $otherToken] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 11:00');
    ['uuid' => $nulled, 'token' => $nulledToken] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 14:00');
    DB::table('tenant_luna.bookings')->where('uuid', $nulled)->update(['manage_token_hash' => null]);
    $notFound = marEnvelope('not_found', 404, apiSentence('api.status.not_found'));

    marRead($this, $uuid, null)->assertStatus(400)->assertExactJson(marEnvelope('invalid_request', 400, apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL)));

    $refusals = [
        marRead($this, $uuid, ManageToken::generate()->plain),
        marRead($this, $uuid, $otherToken),
        marRead($this, $nulled, $nulledToken),
        marRead($this, (string) Str::uuid(), $token),
        marRead($this, 'not-a-uuid-at-all', $token),
    ];

    marRead($this, $other, $otherToken)->assertOk();
    marClockAt('2026-12-10 08:00:00'); // thirty days and a morning after the booking ended
    $refusals[] = marRead($this, $uuid, $token);

    foreach ($refusals as $refusal) {
        $refusal->assertNotFound()->assertExactJson($notFound);
    }

    expect(array_unique(array_map(fn (TestResponse $refusal): string => (string) $refusal->getContent(), $refusals)))->toHaveCount(1);
});

it('answers 400 invalid_request with the field\'s sentence for {case}, once the credential has passed', function (array $query, string $detail) {
    /** @var array<string, string> $query */
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');

    $this->getJson(marUrl($uuid, ['token' => $token, ...$query]))
        ->assertStatus(400)
        ->assertExactJson(marEnvelope('invalid_request', 400, $detail));
})->with([
    'to before from' => [['from' => '2026-11-10', 'to' => '2026-11-09'], fn (): string => apiSentence('api.validation.to.after')],
    'to equal to from' => [['from' => '2026-11-09', 'to' => '2026-11-09'], fn (): string => apiSentence('api.validation.to.after')],
    'a span over 62 days' => [['from' => '2026-11-09', 'to' => '2027-01-11'], fn (): string => apiChoice('api.validation.to.max', DayWindow::MAX_DAYS)],
    'a from with one-digit parts' => [['from' => '2026-11-9', 'to' => '2026-11-16'], fn (): string => apiSentence('api.validation.from.day')],
    'a from that is no date' => [['from' => '2026-02-30', 'to' => '2026-11-16'], fn (): string => apiSentence('api.validation.from.day')],
    'no from' => [['to' => '2026-11-16'], fn (): string => apiSentence('api.validation.from.day')],
    'no to' => [['from' => '2026-11-09'], fn (): string => apiSentence('api.validation.to.day')],
]);

it('takes exactly 62 days, and judges a failed credential before a malformed window, in the order the reschedule route judges a failed credential before a malformed body', function () {
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    $wrong = ManageToken::generate()->plain;

    expect(marDays(marRead($this, $uuid, $token, '2026-11-09', '2027-01-10')))->toHaveCount(62);

    marRead($this, $uuid, $wrong, '2026-11-10', '2026-11-09')->assertNotFound();
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), ['token' => $wrong, 'start' => 'tomorrow'])->assertNotFound();

    marRead($this, $uuid, null, '2026-11-10', '2026-11-09')->assertStatus(400)->assertJsonPath('data.detail', apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL));
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$uuid.'/reschedule'), ['start' => 'tomorrow'])->assertStatus(400)->assertJsonPath('data.detail', apiSentence(ErrorEnvelope::MISSING_TOKEN_DETAIL));
});

it('answers every day empty for {case}, where the move would answer unavailable, and no next_available_from, which is the public read\'s alone', function (string $change) {
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');

    match ($change) {
        'withdrawn' => DB::table('tenant_luna.services')->where('name', 'Haircut')->update(['status' => 'inactive']),
        'deactivated' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->update(['status' => 'inactive']),
        'unassigned' => DB::table('tenant_luna.service_staff')->where('service_id', marId('services', 'Haircut'))->where('staff_id', marId('staff', 'Elena Papadaki'))->delete(),
        default => throw new LogicException($change),
    };

    marRead($this, $uuid, $token, '2026-11-09', '2026-11-12')->assertOk()->assertExactJson(['2026-11-09' => [], '2026-11-10' => [], '2026-11-11' => []]);
})->with([
    'a service withdrawn from the catalog' => ['withdrawn'],
    'a sold member since deactivated' => ['deactivated'],
    'a sold member no longer assigned the service' => ['unassigned'],
]);

it('judges nothing about status: a cancelled booking and a completed one are answered like any other', function () {
    StartInterval::set('tenant_luna', 5);
    ['uuid' => $cancelled, 'token' => $cancelledToken] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    ['uuid' => $completed, 'token' => $completedToken] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 11:00');
    $this->postJson(tenantUrl('luna', '/api/v1/bookings/'.$cancelled.'/cancel'), ['token' => $cancelledToken])->assertOk();
    DB::table('tenant_luna.bookings')->where('uuid', $completed)->update(['status' => 'completed']);

    expect(marDays(marRead($this, $cancelled, $cancelledToken, '2026-11-09', '2026-11-10'))['2026-11-09'])->toBe([
        ...array_slice(marEvery('2026-11-09', '09:00', '12:15'), 1),
        ...marEvery('2026-11-09', '14:00', '17:15'),
    ])->and(marDays(marRead($this, $completed, $completedToken, '2026-11-09', '2026-11-10'))['2026-11-09'])->toBe([
        ...array_diff(marEvery('2026-11-09', '09:00', '12:15'), [marUtc('2026-11-09 11:00')]),
        ...marEvery('2026-11-09', '14:00', '17:15'),
    ]);
});

it('accepts 120 requests a minute from one address, answers the 121st with 429 in the envelope, and counts none of them against the public read\'s budget or the general one', function () {
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    $statuses = [];

    for ($request = 1; $request <= AppServiceProvider::MOVE_AVAILABILITY_REQUESTS_PER_MINUTE; $request++) {
        $statuses[marRead($this, $uuid, $token, '2026-11-09', '2026-11-10')->status()] = true;
    }

    expect(array_keys($statuses))->toBe([200]);

    marRead($this, $uuid, $token, '2026-11-09', '2026-11-10')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '120')
        ->assertExactJson(marEnvelope('rate_limited', 429, apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)));

    marPublic($this, [['Haircut', null]])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120')
        ->assertHeader('X-RateLimit-Remaining', '119');

    $this->getJson(tenantUrl('luna', '/api/v1/services'))
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '299');
});

it('answers no-store, answers a cross-origin read and its preflight with permissive CORS, and leaves the connection free of any search path change', function () {
    ['uuid' => $uuid, 'token' => $token] = marBook($this, [['Haircut', 'Elena Papadaki']], '2026-11-09 09:00');
    $url = marUrl($uuid, ['token' => $token, 'from' => '2026-11-09', 'to' => '2026-11-10']);

    $this->getJson($url, ['Origin' => 'https://www.salon-x.example'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeaderMissing('ETag')
        ->assertHeaderMissing('Last-Modified')
        ->assertHeaderMissing('Expires');

    $this->call('OPTIONS', $url, server: [
        'HTTP_ORIGIN' => 'https://www.salon-x.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public')
        ->and(DB::transactionLevel())->toBe(0);
});
