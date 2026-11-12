<?php

use App\Application\Clock;
use App\Application\Dto\DayWindow;
use App\Domain\Availability\CalendarDay;
use App\Domain\Chain\Chain;
use App\Http\Controllers\Admin\WizardAvailabilityController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\ErrorEnvelope;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\PublicAvailabilityRequest;
use App\Http\Requests\WizardAvailabilityRequest;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\SystemClock;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FixedClock;
use Tests\Support\StartInterval;
use Tests\Support\WeeklyHours;

pest()->use(RefreshDatabase::class);

/*
 * GET /api/v1/availability on the wire, over real rows: what luna answers, what a pick and the
 * same-staff preference do, what a booking removes and a lapsed hold does not, the two window
 * edges, the shape and the zone of the answer, the two clock-change days, every refusal, the
 * limit, the cache header and the budget. The read opens no transaction, so the one this suite
 * wraps each test in is harmless; a held item's expiry is set against the database's now, the
 * clock the predicate reads, while the clock the use case reads is bound here per test.
 *
 * This file is the cold base: the mask cache is bound over the null store, so every answer here
 * comes from rows alone, and two tests may write rows between reads without bumping a revision.
 * The warm reads, and the comparison against these answers, live in AvailabilityCacheTest.
 *
 * The tenant's start interval is 15 unless a test sets it. A test whose subject is the engine's
 * five-minute grid sets 5, so the answer is every free step; the interval's own tests leave the
 * default or set the value they prove.
 *
 * 2026-04-06 is a Monday and April in Athens is UTC+3, so 09:00 local is 06:00Z.
 */

beforeEach(function () {
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
});

function availabilityClockAt(string $text, string $zone = 'UTC'): void
{
    app()->instance(Clock::class, FixedClock::at($text, $zone));
}

/**
 * @param  array<string, mixed>  $query
 */
function availabilityUrl(string $slug, array $query): string
{
    return tenantUrl($slug, '/api/v1/availability?'.http_build_query($query));
}

/**
 * The query string of a well-formed request: the items as one JSON string, each with its
 * staff_id explicit, the way the booking app sends them.
 *
 * @param  list<array{int, int|null}>  $items
 * @return array<string, string>
 */
function availabilityQueryString(array $items, string $from, string $to, ?bool $sameStaff = null): array
{
    $query = [
        'items' => (string) json_encode(array_map(fn (array $item): array => ['service_id' => $item[0], 'staff_id' => $item[1]], $items)),
        'from' => $from,
        'to' => $to,
    ];

    if ($sameStaff !== null) {
        $query['same_staff'] = $sameStaff ? 'true' : 'false';
    }

    return $query;
}

/**
 * Every wall-clock reading from one to another inclusive on a day in a zone, five minutes
 * apart unless told otherwise, spelled as the wire spells a start: RFC 3339 in UTC.
 *
 * @return list<string>
 */
function utcEvery(string $day, string $from, string $to, string $zone = 'Europe/Athens', int $minutes = 5): array
{
    $local = new DateTimeZone($zone);
    $utc = new DateTimeZone('UTC');
    $end = new DateTimeImmutable($day.' '.$to, $local);
    $starts = [];

    for ($at = new DateTimeImmutable($day.' '.$from, $local); $at <= $end; $at = $at->add(new DateInterval('PT'.$minutes.'M'))) {
        $starts[] = $at->setTimezone($utc)->format(DateTimeImmutable::RFC3339);
    }

    return $starts;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function availabilityStaff(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.staff')->insertGetId(['name' => $name, 'status' => 'active', ...$overrides]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function availabilityService(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.services')->insertGetId(serviceRow($name, $overrides));
}

function availabilityAssign(string $schema, int $serviceId, int ...$staffIds): void
{
    foreach ($staffIds as $staffId) {
        DB::table($schema.'.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]);
    }
}

function availabilityRule(string $schema, int $staffId, int $weekday, string $start, string $end): void
{
    WeeklyHours::add($schema, $staffId, $weekday, $start, $end);
}

/**
 * A booking in the given status with one item on the staff member, the block range as local
 * wall-clock readings in Athens, and its expiry, when it has one, against the database's now.
 */
function availabilityItem(string $schema, int $staffId, string $status, string $blockStart, string $blockEnd, ?string $expiry = null): int
{
    $utc = new DateTimeZone('UTC');
    $start = (new DateTimeImmutable($blockStart, new DateTimeZone('Europe/Athens')))->setTimezone($utc)->format('Y-m-d H:i:sP');
    $end = (new DateTimeImmutable($blockEnd, new DateTimeZone('Europe/Athens')))->setTimezone($utc)->format('Y-m-d H:i:sP');

    $bookingId = DB::table($schema.'.bookings')->insertGetId([
        ...bookingRow(),
        'status' => $status,
        'hold_class' => $expiry === null ? null : 'checkout',
        'hold_expires_at' => match ($expiry) {
            'live' => DB::raw("now() + interval '1 hour'"),
            'lapsed' => DB::raw("now() - interval '1 minute'"),
            null => null,
            default => throw new InvalidArgumentException($expiry),
        },
    ]);

    DB::table($schema.'.booking_items')->insert(itemRow($bookingId, $staffId, $start, $end));

    return $bookingId;
}

function lunaId(string $table, string $name): int
{
    $id = DB::table('tenant_luna.'.$table)->where('name', $name)->value('id');

    assert(is_int($id));

    return $id;
}

/**
 * A tenant-wide blackout on each of the days, luna's or any tenant's: no one works them.
 */
function availabilityClosed(string $schema, string ...$days): void
{
    foreach ($days as $day) {
        DB::table($schema.'.blackouts')->insert(['staff_id' => null, 'date' => $day, 'reason' => 'Closed']);
    }
}

/**
 * @return array{code: string, message: string, data: array{status: int, detail: string}}
 */
function availabilityEnvelope(string $code, int $status, string $detail): array
{
    return ['code' => $code, 'message' => $code, 'data' => ['status' => $status, 'detail' => $detail]];
}

it('binds the system clock outside a test', function () {
    expect(app(Clock::class)::class)->toBe(SystemClock::class);
});

it('answers on luna over a week the grid positions where either person can start the haircut inside their rules, from now plus the lead time, the split day showing the lunch gap and Sunday empty', function () {
    seedDemo();
    StartInterval::set('tenant_luna', 5);
    availabilityClockAt('2026-04-06 07:32:00'); // 10:32 in Athens on the Monday

    $response = $this->getJson(availabilityUrl('luna', availabilityQueryString([[lunaId('services', 'Haircut'), null]], '2026-04-06', '2026-04-13')))->assertOk();

    // Monday is Elena's alone, 09:00 to 13:00 and 14:00 to 18:00, the 45 minutes fitting until
    // 12:15 and 17:15; from Tuesday Nikos covers her lunch through to 18:15; Saturday is his.
    $response->assertExactJson([
        '2026-04-06' => [...utcEvery('2026-04-06', '10:35', '12:15'), ...utcEvery('2026-04-06', '14:00', '17:15')],
        '2026-04-07' => utcEvery('2026-04-07', '09:00', '18:15'),
        '2026-04-08' => utcEvery('2026-04-08', '09:00', '18:15'),
        '2026-04-09' => utcEvery('2026-04-09', '09:00', '18:15'),
        '2026-04-10' => utcEvery('2026-04-10', '09:00', '18:15'),
        '2026-04-11' => utcEvery('2026-04-11', '10:00', '18:15'),
        '2026-04-12' => [],
    ]);

    expect(array_keys((array) $response->json()))->toBe(['2026-04-06', '2026-04-07', '2026-04-08', '2026-04-09', '2026-04-10', '2026-04-11', '2026-04-12'])
        ->and($response->json('2026-04-06.0'))->toBe('2026-04-06T07:35:00+00:00');
});

it('answers only the starts on the tenant\'s start interval, read on its wall clock: a quarter hour by default, 10:45 first on a morning whose grid opens at 10:35, and every five minutes at 5', function () {
    seedDemo();
    availabilityClockAt('2026-04-06 07:32:00'); // 10:32 in Athens on the Monday
    $url = availabilityUrl('luna', availabilityQueryString([[lunaId('services', 'Haircut'), null]], '2026-04-06', '2026-04-08'));

    $this->getJson($url)->assertOk()->assertExactJson([
        '2026-04-06' => [...utcEvery('2026-04-06', '10:45', '12:15', minutes: 15), ...utcEvery('2026-04-06', '14:00', '17:15', minutes: 15)],
        '2026-04-07' => utcEvery('2026-04-07', '09:00', '18:15', minutes: 15),
    ]);

    StartInterval::set('tenant_luna', 5);

    $this->getJson($url)->assertOk()->assertExactJson([
        '2026-04-06' => [...utcEvery('2026-04-06', '10:35', '12:15'), ...utcEvery('2026-04-06', '14:00', '17:15')],
        '2026-04-07' => utcEvery('2026-04-07', '09:00', '18:15'),
    ]);
});

it('aligns the interval to the tenant\'s wall clock and not to UTC: a tenant in Asia/Kolkata at 60 is offered its whole hours, which the wire spells at half past', function () {
    provisionTenant('east', ['timezone' => 'Asia/Kolkata']);
    StartInterval::set('tenant_east', 60);
    availabilityClockAt('2026-04-05 12:00:00');
    $ann = availabilityStaff('tenant_east', 'Ann');
    $cut = availabilityService('tenant_east', 'Cut', ['duration_min' => 30]);
    availabilityAssign('tenant_east', $cut, $ann);
    availabilityRule('tenant_east', $ann, 1, '09:00:00', '12:00:00');

    // Kolkata is UTC+05:30, so a whole hour there is half past on the wire; a filter reading
    // the minute in UTC would keep none of these and offer 09:30, 10:30 and 11:30 instead.
    $this->getJson(availabilityUrl('east', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => ['2026-04-06T03:30:00+00:00', '2026-04-06T04:30:00+00:00', '2026-04-06T05:30:00+00:00']]);
});

it('judges a chain by its first start alone: at 15, a chain whose first segment lasts 20 minutes is offered on the quarter hour, although its second segment then starts at twenty past', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-05 12:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $wash = availabilityService('tenant_acme', 'Wash', ['duration_min' => 20]);
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 30]);
    availabilityAssign('tenant_acme', $wash, $ann);
    availabilityAssign('tenant_acme', $cut, $ann);
    availabilityRule('tenant_acme', $ann, 1, '09:00:00', '11:00:00');

    // The 50-minute chain fits from 09:00 to 10:10 on the grid; the interval keeps the quarter
    // hours among those, the 09:00 chain's cut at 09:20 and the 09:15 chain's at 09:35.
    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$wash, $ann], [$cut, $ann]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => utcEvery('2026-04-06', '09:00', '10:00', minutes: 15)]);
});

it('offers nothing on luna past today plus the horizon of sixty days, though the person works that day', function () {
    seedDemo();
    StartInterval::set('tenant_luna', 5);
    availabilityClockAt('2026-04-06 05:00:00');

    $this->getJson(availabilityUrl('luna', availabilityQueryString([[lunaId('services', 'Haircut'), null]], '2026-06-04', '2026-06-08')))
        ->assertOk()
        ->assertExactJson([
            '2026-06-04' => utcEvery('2026-06-04', '09:00', '18:15'),
            '2026-06-05' => utcEvery('2026-06-05', '09:00', '18:15'),
            '2026-06-06' => [],
            '2026-06-07' => [],
        ]);
});

it('answers an empty window with next_available_from, the first later day holding a start, read past the window\'s edge: a Sunday on luna names the Monday after it', function () {
    seedDemo();
    availabilityClockAt('2026-04-06 05:00:00');

    $response = $this->getJson(availabilityUrl('luna', availabilityQueryString([[lunaId('services', 'Haircut'), null]], '2026-04-12', '2026-04-13')))->assertOk();

    $response->assertExactJson(['2026-04-12' => [], 'next_available_from' => '2026-04-13']);

    expect(array_keys((array) $response->json()))->toBe(['2026-04-12', 'next_available_from']);
});

it('reads past a blackout and past the first seven days after the window: luna closed the week after a Sunday names the Monday after that week', function () {
    seedDemo();
    availabilityClockAt('2026-04-06 05:00:00');
    availabilityClosed('tenant_luna', '2026-04-13', '2026-04-14', '2026-04-15', '2026-04-16', '2026-04-17', '2026-04-18');

    $this->getJson(availabilityUrl('luna', availabilityQueryString([[lunaId('services', 'Haircut'), null]], '2026-04-12', '2026-04-13')))
        ->assertOk()
        ->assertExactJson(['2026-04-12' => [], 'next_available_from' => '2026-04-20']);
});

it('names the last day inside the horizon and nothing past it: luna closed up to its horizon\'s end answers null, and the last day once that day opens', function () {
    seedDemo();
    availabilityClockAt('2026-04-06 05:00:00');
    availabilityClosed('tenant_luna', '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05');
    $url = availabilityUrl('luna', availabilityQueryString([[lunaId('services', 'Haircut'), null]], '2026-06-01', '2026-06-02'));

    // Today is 2026-04-06 and the haircut's horizon sixty days, so the 5th of June is the last
    // day a customer may take; the Saturday after it is open on luna's hours and never named.
    $this->getJson($url)->assertOk()->assertExactJson(['2026-06-01' => [], 'next_available_from' => null]);

    DB::table('tenant_luna.blackouts')->where('date', '2026-06-05')->delete();

    $this->getJson($url)->assertOk()->assertExactJson(['2026-06-01' => [], 'next_available_from' => '2026-06-05']);
});

it('walks a chain of two up to the smaller of its two horizons: the Monday a week on is named at seven days and not at six', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $wash = availabilityService('tenant_acme', 'Wash', ['duration_min' => 30, 'horizon_days' => 60]);
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 30, 'horizon_days' => 7, 'sort' => 1]);
    availabilityAssign('tenant_acme', $wash, $ann);
    availabilityAssign('tenant_acme', $cut, $ann);
    availabilityRule('tenant_acme', $ann, 1, '09:00:00', '11:00:00');
    $url = availabilityUrl('acme', availabilityQueryString([[$wash, null], [$cut, null]], '2026-04-07', '2026-04-08'));

    $this->getJson($url)->assertOk()->assertExactJson(['2026-04-07' => [], 'next_available_from' => '2026-04-13']);

    DB::table('tenant_acme.services')->where('id', $cut)->update(['horizon_days' => 6]);

    $this->getJson($url)->assertOk()->assertExactJson(['2026-04-07' => [], 'next_available_from' => null]);
});

it('narrows the starts to the picked person, and answers 400 naming the item for a pick not listed on the service', function () {
    seedDemo();
    StartInterval::set('tenant_luna', 5);
    availabilityClockAt('2026-04-06 05:00:00');
    $haircut = lunaId('services', 'Haircut');
    $beard = lunaId('services', 'Beard trim');
    $elena = lunaId('staff', 'Elena Papadaki');

    $this->getJson(availabilityUrl('luna', availabilityQueryString([[$haircut, $elena]], '2026-04-07', '2026-04-08')))
        ->assertOk()
        ->assertExactJson(['2026-04-07' => [...utcEvery('2026-04-07', '09:00', '12:15'), ...utcEvery('2026-04-07', '14:00', '17:15')]]);

    $this->getJson(availabilityUrl('luna', availabilityQueryString([[$haircut, null], [$beard, $elena]], '2026-04-07', '2026-04-08')))
        ->assertStatus(400)
        ->assertExactJson(availabilityEnvelope('invalid_request', 400, apiSentence('api.validation.item.staff_unqualified', ['n' => '2'])));
});

it('refuses under same_staff a start the plain form offers, on a chain no one member can carry through', function () {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $ben = availabilityStaff('tenant_acme', 'Ben');
    $first = availabilityService('tenant_acme', 'First', ['duration_min' => 30, 'processing_gap_min' => 30]);
    $second = availabilityService('tenant_acme', 'Second', ['duration_min' => 30, 'sort' => 1]);
    availabilityAssign('tenant_acme', $first, $ann, $ben);
    availabilityAssign('tenant_acme', $second, $ann, $ben);
    availabilityRule('tenant_acme', $ann, 1, '09:00:00', '10:00:00');
    availabilityRule('tenant_acme', $ben, 1, '10:00:00', '11:00:00');

    // Ann can open the chain until 09:30 and Ben can close it an hour later; neither can do both.
    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$first, null], [$second, null]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => utcEvery('2026-04-06', '09:00', '09:30')]);

    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$first, null], [$second, null]], '2026-04-06', '2026-04-07', true)))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => [], 'next_available_from' => null]);
});

it('removes the starts a confirmed booking\'s block range forbids, buffers included, and none for a hold that has lapsed', function () {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 30, 'buffer_before_min' => 10, 'buffer_after_min' => 10]);
    availabilityAssign('tenant_acme', $cut, $ann);
    availabilityRule('tenant_acme', $ann, 1, '09:00:00', '12:00:00');
    $booking = availabilityItem('tenant_acme', $ann, 'confirmed', '2026-04-06 09:50', '2026-04-06 10:40');

    // A start needs ten minutes before and forty after it free: nothing from 09:15 to 10:45.
    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => [...utcEvery('2026-04-06', '09:00', '09:10'), ...utcEvery('2026-04-06', '10:50', '11:30')]]);

    DB::table('tenant_acme.bookings')->where('id', $booking)->update(['status' => 'pending', 'hold_class' => 'checkout', 'hold_expires_at' => DB::raw("now() - interval '1 minute'")]);

    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => utcEvery('2026-04-06', '09:00', '11:30')]);
});

it('offers a chain running past local midnight on the window\'s last day only once the day after is open, and a cleanup buffer crossing that midnight regardless: the window was widened', function () {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $ben = availabilityStaff('tenant_acme', 'Ben');
    $first = availabilityService('tenant_acme', 'First', ['duration_min' => 60]);
    $second = availabilityService('tenant_acme', 'Second', ['duration_min' => 60, 'sort' => 1]);
    $late = availabilityService('tenant_acme', 'Late', ['duration_min' => 60, 'buffer_after_min' => 30, 'sort' => 2]);
    availabilityAssign('tenant_acme', $first, $ann);
    availabilityAssign('tenant_acme', $second, $ann);
    availabilityAssign('tenant_acme', $late, $ben);
    availabilityRule('tenant_acme', $ann, 1, '22:00:00', '24:00:00');
    availabilityRule('tenant_acme', $ben, 1, '22:00:00', '24:00:00');

    $chain = availabilityQueryString([[$first, null], [$second, null]], '2026-04-06', '2026-04-07');

    $this->getJson(availabilityUrl('acme', $chain))->assertOk()->assertExactJson(['2026-04-06' => utcEvery('2026-04-06', '22:00', '22:00')]);

    availabilityRule('tenant_acme', $ann, 2, '00:00:00', '01:00:00');

    $this->getJson(availabilityUrl('acme', $chain))->assertOk()->assertExactJson(['2026-04-06' => utcEvery('2026-04-06', '22:00', '23:00')]);

    // Ben's Tuesday is closed, and 23:00 is still offered: the buffer needs no opening hours,
    // only the slots after midnight, which lie inside the widened window.
    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$late, null]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => utcEvery('2026-04-06', '22:00', '23:00')]);
});

it('offers a start on the window\'s first day whose setup buffer falls on the day before when that day is free, and refuses it when that day is booked', function () {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    availabilityClockAt('2026-04-06 12:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $early = availabilityService('tenant_acme', 'Early', ['duration_min' => 30, 'buffer_before_min' => 30]);
    availabilityAssign('tenant_acme', $early, $ann);
    availabilityRule('tenant_acme', $ann, 2, '00:00:00', '01:00:00');

    $query = availabilityQueryString([[$early, null]], '2026-04-07', '2026-04-08');

    $this->getJson(availabilityUrl('acme', $query))->assertOk()->assertExactJson(['2026-04-07' => utcEvery('2026-04-07', '00:00', '00:30')]);

    availabilityItem('tenant_acme', $ann, 'confirmed', '2026-04-06 23:45', '2026-04-07 00:00');

    $this->getJson(availabilityUrl('acme', $query))->assertOk()->assertExactJson(['2026-04-07' => utcEvery('2026-04-07', '00:30', '00:30')]);
});

it('keys every day of the window once, in order, empty ones as empty lists and none outside it, by the tenant\'s date and not the UTC one, as RFC 3339 instants in UTC', function () {
    provisionTenant('west', ['timezone' => 'America/Los_Angeles']);
    StartInterval::set('tenant_west', 5);
    availabilityClockAt('2026-04-06 12:00:00');
    $ann = availabilityStaff('tenant_west', 'Ann');
    $cut = availabilityService('tenant_west', 'Cut', ['duration_min' => 30]);
    availabilityAssign('tenant_west', $cut, $ann);
    availabilityRule('tenant_west', $ann, 1, '19:00:00', '23:00:00');
    availabilityRule('tenant_west', $ann, 2, '00:00:00', '01:00:00');

    $response = $this->getJson(availabilityUrl('west', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-09')))->assertOk();

    // Monday evening in Los Angeles is already Tuesday in UTC, and stays under Monday's key;
    // a start just after local midnight on Tuesday stays under Tuesday's.
    $response->assertExactJson([
        '2026-04-06' => utcEvery('2026-04-06', '19:00', '22:30', 'America/Los_Angeles'),
        '2026-04-07' => utcEvery('2026-04-07', '00:00', '00:30', 'America/Los_Angeles'),
        '2026-04-08' => [],
    ]);

    $body = (array) $response->json();
    $every = [];

    foreach ($body as $starts) {
        foreach (is_array($starts) ? $starts : [$starts] as $start) {
            $every[] = $start;
        }
    }

    expect(array_keys($body))->toBe(['2026-04-06', '2026-04-07', '2026-04-08'])
        ->and($response->json('2026-04-06.0'))->toBe('2026-04-07T02:00:00+00:00')
        ->and($response->json('2026-04-07.0'))->toBe('2026-04-07T07:00:00+00:00')
        ->and($every)->toHaveCount(43 + 7)
        ->and($every)->each->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');

    // An empty window carries one key more, after its days: the next day holding a start, by
    // the tenant's date, the Monday whose evening starts the wire spells on the Tuesday.
    $empty = $this->getJson(availabilityUrl('west', availabilityQueryString([[$cut, null]], '2026-04-08', '2026-04-10')))->assertOk();

    expect(array_keys((array) $empty->json()))->toBe(['2026-04-08', '2026-04-09', 'next_available_from'])
        ->and($empty->json('next_available_from'))->toBe('2026-04-13');
});

it('answers every day empty for a service nobody performs, and no later day either', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $cut = availabilityService('tenant_acme', 'Cut');

    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-08')))
        ->assertOk()
        ->assertExactJson(['2026-04-06' => [], '2026-04-07' => [], 'next_available_from' => null]);
});

it('offers no start inside the missing hour of 2026-03-29 in Europe/Athens', function () {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    availabilityClockAt('2026-03-28 12:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 30]);
    availabilityAssign('tenant_acme', $cut, $ann);
    availabilityRule('tenant_acme', $ann, 7, '00:00:00', '24:00:00');

    /** @var list<string> $starts */
    $starts = $this->getJson(availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-03-29', '2026-03-30')))->assertOk()->json('2026-03-29');
    $local = array_map(fn (string $start): string => (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('H:i P'), $starts);

    expect($starts)->toHaveCount(276 - 5)
        ->and(array_unique($starts))->toHaveCount(276 - 5)
        ->and(array_filter($local, fn (string $reading): bool => str_starts_with($reading, '03:')))->toBe([])
        ->and($starts[35])->toBe('2026-03-29T00:55:00+00:00')
        ->and($starts[36])->toBe('2026-03-29T01:00:00+00:00')
        ->and($local[35])->toBe('02:55 +02:00')
        ->and($local[36])->toBe('04:00 +03:00');
});

it('offers one distinct instant per real slot through the repeated hour of 2026-10-25 in Europe/Athens', function () {
    provisionTenant('acme');
    StartInterval::set('tenant_acme', 5);
    availabilityClockAt('2026-10-24 12:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 30]);
    availabilityAssign('tenant_acme', $cut, $ann);
    availabilityRule('tenant_acme', $ann, 7, '00:00:00', '24:00:00');

    /** @var list<string> $starts */
    $starts = $this->getJson(availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-10-25', '2026-10-26')))->assertOk()->json('2026-10-25');
    $local = array_map(fn (string $start): string => (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('H:i P'), $starts);

    expect($starts)->toHaveCount(300 - 5)
        ->and(array_unique($starts))->toHaveCount(300 - 5)
        ->and(array_count_values(array_map(fn (string $reading): string => substr($reading, 0, 2), $local))['03'])->toBe(24)
        ->and($starts)->toContain('2026-10-25T00:30:00+00:00')
        ->and($starts)->toContain('2026-10-25T01:30:00+00:00')
        ->and($local[42])->toBe('03:30 +03:00')
        ->and($local[54])->toBe('03:30 +02:00');
});

it('answers 400 invalid_request with its sentence for {case}', function (array $query, string $detail) {
    provisionTenant('acme');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $ben = availabilityStaff('tenant_acme', 'Ben');
    $cut = availabilityService('tenant_acme', 'Cut');
    $perm = availabilityService('tenant_acme', 'Perm', ['status' => 'inactive', 'sort' => 1]);
    availabilityAssign('tenant_acme', $cut, $ann);
    $ids = ['{cut}' => (string) $cut, '{perm}' => (string) $perm, '{ann}' => (string) $ann, '{ben}' => (string) $ben];
    $replaced = [];

    // The dataset spells the ids as placeholders, since it is written before any row exists.
    foreach ($query as $field => $value) {
        if (! is_string($value)) {
            throw new LogicException('Every value of the dataset is a string.');
        }

        $replaced[(string) $field] = strtr($value, $ids);
    }

    $this->getJson(availabilityUrl('acme', $replaced))
        ->assertStatus(400)
        ->assertExactJson(availabilityEnvelope('invalid_request', 400, $detail));
})->with([
    'a missing staff_id key' => [['items' => '[{"service_id":{cut}}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.no_staff_id', ['n' => '1'])],
    'a missing service_id' => [['items' => '[{"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.no_service_id', ['n' => '1'])],
    'a service id that is a numeric string' => [['items' => '[{"service_id":"{cut}","staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.service_id_integer', ['n' => '1'])],
    'a service id of zero' => [['items' => '[{"service_id":0,"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.service_id_integer', ['n' => '1'])],
    'a staff id that is a word' => [['items' => '[{"service_id":{cut},"staff_id":"ann"}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.staff_id_integer', ['n' => '1'])],
    'a staff id that is true' => [['items' => '[{"service_id":{cut},"staff_id":true}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.staff_id_integer', ['n' => '1'])],
    'an unknown service' => [['items' => '[{"service_id":999,"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.service_unavailable', ['n' => '1'])],
    'an inactive service' => [['items' => '[{"service_id":{perm},"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.service_unavailable', ['n' => '1'])],
    'an inactive service in second place' => [['items' => '[{"service_id":{cut},"staff_id":null},{"service_id":{perm},"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.service_unavailable', ['n' => '2'])],
    'a pick who does not perform the service' => [['items' => '[{"service_id":{cut},"staff_id":{ben}}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.staff_unqualified', ['n' => '1'])],
    'an item that is not an object' => [['items' => '[{cut}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.item.object', ['n' => '1'])],
    'items that are not JSON' => [['items' => 'haircut', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.items.list')],
    'items that are a JSON object' => [['items' => '{"service_id":{cut},"staff_id":null}', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.items.list')],
    'an empty list' => [['items' => '[]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.items.required')],
    'no items at all' => [['from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.items.required')],
    'more items than the cap' => [['items' => '['.str_repeat('{"service_id":{cut},"staff_id":null},', 5).'{"service_id":{cut},"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07'], fn (): string => apiChoice('api.validation.items.max', Chain::MAX_SEGMENTS)],
    'to before from' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-04-07', 'to' => '2026-04-06'], fn (): string => apiSentence('api.validation.to.after')],
    'to equal to from' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-06'], fn (): string => apiSentence('api.validation.to.after')],
    'a span over 62 days' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-06-08'], fn (): string => apiChoice('api.validation.to.max', DayWindow::MAX_DAYS)],
    'a from with one-digit parts' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-4-6', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.from.day')],
    'a from that is no date' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-02-30', 'to' => '2026-04-07'], fn (): string => apiSentence('api.validation.from.day')],
    'no to' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-04-06'], fn (): string => apiSentence('api.validation.to.day')],
    'a same_staff that is a word' => [['items' => '[{"service_id":{cut},"staff_id":null}]', 'from' => '2026-04-06', 'to' => '2026-04-07', 'same_staff' => 'maybe'], fn (): string => apiSentence('api.validation.same_staff')],
]);

it('is bracketed by the two items that fix the cap: five items pass and exactly 62 days pass', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 5]);
    availabilityAssign('tenant_acme', $cut, $ann);

    $response = $this->getJson(availabilityUrl('acme', availabilityQueryString(array_fill(0, 5, [$cut, null]), '2026-04-06', '2026-06-07')))->assertOk();

    // Ann has no hours, so the window is empty and carries the one key more, null: its end lies
    // past the horizon, so nothing further is read.
    expect((array) $response->json())->toHaveCount(62 + 1)
        ->and($response->json('next_available_from'))->toBeNull();
});

it('reads same_staff spelled {spelling} as a query string spells a boolean', function (string $spelling, bool $sameStaff) {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $ben = availabilityStaff('tenant_acme', 'Ben');
    $first = availabilityService('tenant_acme', 'First', ['duration_min' => 30]);
    $second = availabilityService('tenant_acme', 'Second', ['duration_min' => 30, 'sort' => 1]);
    availabilityAssign('tenant_acme', $first, $ann);
    availabilityAssign('tenant_acme', $second, $ben);
    availabilityRule('tenant_acme', $ann, 1, '09:00:00', '10:00:00');
    availabilityRule('tenant_acme', $ben, 1, '09:00:00', '10:00:00');

    // Under the preference nobody carries both segments, so the starts vanish.
    $this->getJson(availabilityUrl('acme', [...availabilityQueryString([[$first, null], [$second, null]], '2026-04-06', '2026-04-07'), 'same_staff' => $spelling]))
        ->assertOk()
        ->assertExactJson($sameStaff ? ['2026-04-06' => [], 'next_available_from' => null] : ['2026-04-06' => utcEvery('2026-04-06', '09:00', '09:00')]);
})->with([
    'true' => ['true', true],
    'false' => ['false', false],
    '1' => ['1', true],
    '0' => ['0', false],
]);

it('accepts 120 requests a minute from one address, answers the 121st with 429 in the envelope, and counts none of them against the general limit', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $cut = availabilityService('tenant_acme', 'Cut');
    availabilityAssign('tenant_acme', $cut, $ann);
    $url = availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-07'));
    $statuses = [];

    for ($request = 1; $request <= AppServiceProvider::AVAILABILITY_REQUESTS_PER_MINUTE; $request++) {
        $statuses[$this->getJson($url)->status()] = true;
    }

    expect(array_keys($statuses))->toBe([200]);

    $this->getJson($url)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '120')
        ->assertExactJson(availabilityEnvelope('rate_limited', 429, apiSentence(ErrorEnvelope::RATE_LIMITED_DETAIL)));

    $this->getJson(tenantUrl('acme', '/api/v1/services'))
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '299');

    $this->call('GET', $url, server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_ACCEPT' => 'application/json'])->assertOk();
});

it('carries no cache-permitting header: the answer is advisory and stale the moment it is sent', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $ann = availabilityStaff('tenant_acme', 'Ann');
    $cut = availabilityService('tenant_acme', 'Cut');
    availabilityAssign('tenant_acme', $cut, $ann);

    $this->getJson(availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-07')))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('ETag')
        ->assertHeaderMissing('Last-Modified')
        ->assertHeaderMissing('Expires');
});

it('answers three segments over ten staff members and a 60-day window in under one second', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-05 12:00:00');
    $cut = availabilityService('tenant_acme', 'Cut', ['duration_min' => 30, 'processing_gap_min' => 30]);
    $colour = availabilityService('tenant_acme', 'Colour', ['duration_min' => 60, 'sort' => 1]);
    $finish = availabilityService('tenant_acme', 'Finish', ['duration_min' => 45, 'buffer_after_min' => 10, 'sort' => 2]);
    $staff = [];

    foreach (range(1, 10) as $position) {
        $staffId = availabilityStaff('tenant_acme', 'Staff '.$position, ['sort' => $position]);
        $staff[] = $staffId;

        foreach (range(1, 6) as $weekday) {
            availabilityRule('tenant_acme', $staffId, $weekday, '09:00:00', '18:00:00');
        }

        foreach (range(0, 8) as $week) {
            $day = CalendarDay::plusDays('2026-04-06', $week * 7 + $position % 5);
            availabilityItem('tenant_acme', $staffId, 'confirmed', $day.' 11:00', $day.' 12:00');
        }
    }

    availabilityAssign('tenant_acme', $cut, ...$staff);
    availabilityAssign('tenant_acme', $colour, ...$staff);
    availabilityAssign('tenant_acme', $finish, ...$staff);
    $url = availabilityUrl('acme', availabilityQueryString([[$cut, null], [$colour, null], [$finish, null]], '2026-04-06', '2026-06-05'));

    $began = hrtime(true);
    $response = $this->getJson($url)->assertOk();
    $seconds = (hrtime(true) - $began) / 1_000_000_000;

    expect((array) $response->json())->toHaveCount(60)
        ->and($response->json('2026-04-06'))->not->toBe([])
        ->and($seconds)->toBeLessThan(1.0, sprintf('%.3f seconds', $seconds));
});

it('answers a cross-origin read and a preflight with permissive CORS, and leaves the connection free of any search path change', function () {
    provisionTenant('acme');
    availabilityClockAt('2026-04-06 05:00:00');
    $cut = availabilityService('tenant_acme', 'Cut');
    $url = availabilityUrl('acme', availabilityQueryString([[$cut, null]], '2026-04-06', '2026-04-07'));

    $this->getJson($url, ['Origin' => 'https://www.salon-x.example'])->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');

    $this->call('OPTIONS', $url, server: [
        'HTTP_ORIGIN' => 'https://www.salon-x.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

it('takes the query through an abstract request with two final roads, the public one on this route and the wizard\'s on the admin\'s, each naming its pool', function () {
    $base = new ReflectionClass(AvailabilityRequest::class);
    $roads = array_map(fn (string $class): ReflectionClass => new ReflectionClass($class), [PublicAvailabilityRequest::class, WizardAvailabilityRequest::class]);
    $takes = function (string $controller): ?string {
        $type = ((new ReflectionMethod($controller, '__invoke'))->getParameters()[0] ?? null)?->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    };

    expect($base->isAbstract())->toBeTrue()
        ->and($base->getMethod('pool')->isAbstract())->toBeTrue()
        ->and(array_map(fn (ReflectionClass $road): bool => $road->isFinal() && $road->getParentClass() !== false && $road->getParentClass()->getName() === AvailabilityRequest::class, $roads))->toBe([true, true])
        ->and($takes(AvailabilityController::class))->toBe(PublicAvailabilityRequest::class)
        ->and($takes(WizardAvailabilityController::class))->toBe(WizardAvailabilityRequest::class);
});
