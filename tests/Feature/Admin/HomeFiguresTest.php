<?php

use App\Application\Clock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * Home's figures for a range: the range_figures prop for the range a `range` query parameter
 * names, its six tiles each beside the period before, and Top services and Top staff.
 *
 * The bookings are written straight to the rows at literal dates, so the clock is pinned first:
 * Wednesday 2026-10-21 at 08:00 in Athens, so this week, from luna's Monday week start, is the
 * 19th to the 25th, the clocks going back on its Sunday, and the week before is the 12th to the
 * 18th. Every member's hours are cleared first; a test gives the hours it reads.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-10-21 08:00', 'Europe/Athens'));
    WeeklyHours::clear('tenant_luna');
});

function figuresOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

function figuresImplicit(): int
{
    $id = DB::table('tenant_luna.staff')->where('is_implicit', true)->value('id');
    assert(is_int($id));

    return $id;
}

/**
 * A booking of luna written straight to the rows: its items one after another from the Athens
 * start given, each a service on a member for the minutes and the price given; the total their
 * sum, the address given or none for an erased customer.
 *
 * @param  list<array{0: string, 1: string|int, 2: int, 3: int}>  $items  service name, member name or id, minutes, price
 */
function figuresBooking(string $status, string $start, array $items = [['Haircut', 'Elena Papadaki', 45, 3500]], ?string $email = 'casey@example.com', string $currency = 'EUR'): int
{
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status,
        'customer_name' => $email === null ? null : 'Casey Customer', 'customer_email' => $email,
        'total_minor' => array_sum(array_map(fn (array $item): int => $item[3], $items)), 'currency' => $currency,
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);

    foreach ($items as $sort => [$service, $staff, $minutes, $price]) {
        $to = $from->add(new DateInterval(sprintf('PT%dM', $minutes)));
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $service), 'staff_id' => is_int($staff) ? $staff : LunaFixture::id('staff', $staff),
            'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
            'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to->add(new DateInterval('PT15M'))),
            'price_minor' => $price, 'blocks' => false,
        ]);
        $from = $to;
    }

    return $id;
}

/**
 * One field of a prop read by its dotted path.
 *
 * @param  array<string, mixed>  $prop
 */
function figureAt(array $prop, string $path): mixed
{
    return data_get($prop, $path);
}

/**
 * The range_figures prop as the owner reads it for the query given.
 *
 * @return array<string, mixed>
 */
function figuresProp(TestCase $test, string $query = ''): array
{
    $prop = data_get($test->actingAs(figuresOwner())->get(tenantUrl('luna', '/admin'.$query))->assertOk()->viewData('page'), 'props.range_figures');
    assert(is_array($prop));

    /** @var array<string, mixed> $prop */
    return $prop;
}

it('names the range and the period before by their dates for each of Home\'s words, an unknown word and none reading as this week', function (string $query, array $range, array $before) {
    $prop = figuresProp($this, $query);

    expect(figureAt($prop, 'range'))->toBe($range)->and(figureAt($prop, 'before'))->toBe($before);
})->with([
    'today' => ['?range=today', ['word' => 'today', 'from' => '2026-10-21', 'to' => '2026-10-21'], ['from' => '2026-10-20', 'to' => '2026-10-20']],
    'yesterday' => ['?range=yesterday', ['word' => 'yesterday', 'from' => '2026-10-20', 'to' => '2026-10-20'], ['from' => '2026-10-19', 'to' => '2026-10-19']],
    'this week' => ['?range=week', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'], ['from' => '2026-10-12', 'to' => '2026-10-18']],
    'this month' => ['?range=month', ['word' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-31'], ['from' => '2026-09-01', 'to' => '2026-09-30']],
    'an unknown word' => ['?range=decade', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'], ['from' => '2026-10-12', 'to' => '2026-10-18']],
    'none' => ['', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'], ['from' => '2026-10-12', 'to' => '2026-10-18']],
]);

it('reads the week from the tenant\'s own week start', function () {
    settingsOf('luna')->update(['start_of_week' => 7]);

    expect(figureAt(figuresProp($this), 'range'))->toBe(['word' => 'week', 'from' => '2026-10-18', 'to' => '2026-10-24']);
});

it('counts bookings by first start at the range\'s edges: Bookings and Revenue the confirmed and completed, Cancelled and No-shows their own, every other status none', function () {
    figuresBooking('confirmed', '2026-10-25 23:30', items: [['Haircut', 'Elena Papadaki', 45, 4000]]);
    figuresBooking('completed', '2026-10-19 00:00', items: [['Haircut', 'Elena Papadaki', 45, 2000]]);
    figuresBooking('cancelled', '2026-10-20 10:00');
    figuresBooking('no_show', '2026-10-21 10:00');
    foreach (['pending', 'awaiting_approval', 'awaiting_payment', 'rejected', 'expired'] as $status) {
        figuresBooking($status, '2026-10-22 10:00');
    }
    // A minute outside on either side: the last minute of the week before and the first of the
    // next.
    figuresBooking('confirmed', '2026-10-18 23:59', items: [['Haircut', 'Elena Papadaki', 45, 3000]]);
    figuresBooking('cancelled', '2026-10-18 23:59');
    figuresBooking('confirmed', '2026-10-26 00:00', items: [['Haircut', 'Elena Papadaki', 45, 9900]]);
    // A booking whose second item falls in the week is counted by its first start, before it.
    figuresBooking('completed', '2026-10-18 23:30', items: [['Haircut', 'Elena Papadaki', 45, 1000], ['Colour', 'Elena Papadaki', 60, 1000]]);

    $prop = figuresProp($this, '?range=week');

    expect(figureAt($prop, 'tiles.bookings'))->toBe(['value' => 2, 'previous' => 2, 'change' => ['kind' => 'same', 'percent' => 0]])
        ->and(figureAt($prop, 'tiles.revenue'))->toBe(['value' => ['currency' => 'EUR', 'minor' => 6000], 'previous' => ['currency' => 'EUR', 'minor' => 5000], 'change' => ['kind' => 'up', 'percent' => 20], 'elsewhere' => []])
        ->and(figureAt($prop, 'tiles.cancelled'))->toBe(['value' => 1, 'previous' => 1, 'change' => ['kind' => 'same', 'percent' => 0]])
        ->and(figureAt($prop, 'tiles.no_shows'))->toBe(['value' => 1, 'previous' => 0, 'change' => ['kind' => 'from_zero', 'percent' => null]]);
});

it('reads revenue in the tenant\'s currency and lists any other currency beside it, uncompared', function () {
    figuresBooking('confirmed', '2026-10-20 10:00', items: [['Haircut', 'Elena Papadaki', 45, 3500]]);
    figuresBooking('confirmed', '2026-10-20 12:00', items: [['Haircut', 'Elena Papadaki', 45, 1000]], currency: 'USD');

    expect(figureAt(figuresProp($this), 'tiles.revenue'))->toBe([
        'value' => ['currency' => 'EUR', 'minor' => 3500], 'previous' => ['currency' => 'EUR', 'minor' => 0],
        'change' => ['kind' => 'from_zero', 'percent' => null], 'elsewhere' => [['currency' => 'USD', 'minor' => 1000]],
    ])->and(figureAt(figuresProp($this), 'tiles.bookings.value'))->toBe(2);
});

it('reads each tile against the period before for today, yesterday and the month', function () {
    figuresBooking('confirmed', '2026-10-21 10:00');
    figuresBooking('confirmed', '2026-10-21 12:00');
    figuresBooking('confirmed', '2026-10-20 10:00');
    figuresBooking('confirmed', '2026-09-30 23:30');

    expect(figureAt(figuresProp($this, '?range=today'), 'tiles.bookings'))->toBe(['value' => 2, 'previous' => 1, 'change' => ['kind' => 'up', 'percent' => 100]])
        ->and(figureAt(figuresProp($this, '?range=yesterday'), 'tiles.bookings'))->toBe(['value' => 1, 'previous' => 0, 'change' => ['kind' => 'from_zero', 'percent' => null]])
        ->and(figureAt(figuresProp($this, '?range=month'), 'tiles.bookings'))->toBe(['value' => 3, 'previous' => 1, 'change' => ['kind' => 'up', 'percent' => 200]]);
});

it('splits a booking of two services on two members by its items\' own prices, once per service and once per member', function () {
    figuresBooking('confirmed', '2026-10-20 10:00', items: [['Haircut', 'Elena Papadaki', 45, 3500], ['Beard trim', 'Nikos Georgiou', 20, 1500]]);
    // Two of one service on one member: one booking, both prices.
    figuresBooking('completed', '2026-10-21 10:00', items: [['Haircut', 'Elena Papadaki', 45, 3500], ['Haircut', 'Elena Papadaki', 45, 3000]]);
    // A segment on the implicit member stands for the business.
    figuresBooking('confirmed', '2026-10-22 10:00', items: [['Colour', figuresImplicit(), 60, 800]]);
    // Not counted: cancelled, and outside the week.
    figuresBooking('cancelled', '2026-10-22 12:00', items: [['Colour', 'Nikos Georgiou', 60, 9000]]);
    figuresBooking('confirmed', '2026-10-15 12:00', items: [['Colour', 'Nikos Georgiou', 60, 9000]]);

    $prop = figuresProp($this);
    $haircut = LunaFixture::id('services', 'Haircut');
    $beard = LunaFixture::id('services', 'Beard trim');
    $colour = LunaFixture::id('services', 'Colour');

    expect(figureAt($prop, 'tiles.revenue.value'))->toBe(['currency' => 'EUR', 'minor' => 12300])
        ->and(figureAt($prop, 'top_services'))->toBe([
            ['id' => $haircut, 'name' => 'Haircut', 'bookings' => 2, 'revenue' => ['currency' => 'EUR', 'minor' => 10000]],
            ['id' => $beard, 'name' => 'Beard trim', 'bookings' => 1, 'revenue' => ['currency' => 'EUR', 'minor' => 1500]],
            ['id' => $colour, 'name' => 'Colour', 'bookings' => 1, 'revenue' => ['currency' => 'EUR', 'minor' => 800]],
        ])
        ->and(figureAt($prop, 'top_staff'))->toBe([
            ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki', 'business' => false, 'bookings' => 2, 'revenue' => ['currency' => 'EUR', 'minor' => 10000]],
            ['id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'name' => 'Nikos Georgiou', 'business' => false, 'bookings' => 1, 'revenue' => ['currency' => 'EUR', 'minor' => 1500]],
            ['id' => figuresImplicit(), 'name' => 'Luna Hair Studio', 'business' => true, 'bookings' => 1, 'revenue' => ['currency' => 'EUR', 'minor' => 800]],
        ]);
});

it('orders the Top rows by revenue, then bookings, then name, and cuts them at five', function () {
    $ids = [];

    foreach (['Gloss', 'Fringe', 'Ends', 'Dry', 'Curl', 'Braid'] as $sort => $name) {
        $ids[$name] = DB::table('tenant_luna.services')->insertGetId([
            'name' => $name, 'duration_min' => 30, 'currency' => 'EUR', 'payment_mode' => 'onsite', 'status' => 'active', 'sort' => 10 + $sort,
        ]);
    }

    // Braid and Curl tie on revenue and bookings: the name orders them. Dry earns as much in two
    // bookings.
    figuresBooking('confirmed', '2026-10-20 09:00', items: [['Braid', 'Elena Papadaki', 30, 2000]]);
    figuresBooking('confirmed', '2026-10-20 10:00', items: [['Curl', 'Elena Papadaki', 30, 2000]]);
    figuresBooking('confirmed', '2026-10-20 11:00', items: [['Dry', 'Elena Papadaki', 30, 1000]]);
    figuresBooking('confirmed', '2026-10-20 12:00', items: [['Dry', 'Elena Papadaki', 30, 1000]]);
    figuresBooking('confirmed', '2026-10-20 13:00', items: [['Ends', 'Elena Papadaki', 30, 5000]]);
    figuresBooking('confirmed', '2026-10-20 14:00', items: [['Fringe', 'Elena Papadaki', 30, 500]]);
    figuresBooking('confirmed', '2026-10-20 15:00', items: [['Gloss', 'Elena Papadaki', 30, 100]]);

    $rows = figureAt(figuresProp($this), 'top_services');
    assert(is_array($rows));

    expect(array_map(fn (mixed $row): mixed => data_get($row, 'name'), $rows))->toBe(['Ends', 'Dry', 'Braid', 'Curl', 'Fringe']);
});

it('reads an empty range as zero tiles, no comparison and no Top rows', function () {
    $prop = figuresProp($this);

    expect(figureAt($prop, 'tiles.bookings'))->toBe(['value' => 0, 'previous' => 0, 'change' => ['kind' => 'none', 'percent' => null]])
        ->and(figureAt($prop, 'tiles.revenue.value'))->toBe(['currency' => 'EUR', 'minor' => 0])
        ->and(figureAt($prop, 'top_services'))->toBe([])
        ->and(figureAt($prop, 'top_staff'))->toBe([]);
});

it('counts a customer as new whose first confirmed or completed booking ever starts in the range, and as returning any other with one in it', function () {
    // Returning: booked last month and again this week.
    figuresBooking('completed', '2026-09-10 10:00', email: 'ann@example.com');
    figuresBooking('confirmed', '2026-10-20 10:00', email: 'ann@example.com');
    // New: two bookings this week, one customer; the address's case is no second customer.
    figuresBooking('confirmed', '2026-10-20 12:00', email: 'bea@example.com');
    figuresBooking('confirmed', '2026-10-24 12:00', email: 'Bea@Example.com');
    // New: an earlier cancelled booking does not make a customer.
    figuresBooking('cancelled', '2026-09-01 10:00', email: 'cleo@example.com');
    figuresBooking('confirmed', '2026-10-21 10:00', email: 'cleo@example.com');
    // Nobody: only a cancelled booking this week, and an erased customer's booking.
    figuresBooking('cancelled', '2026-10-21 12:00', email: 'dora@example.com');
    figuresBooking('confirmed', '2026-10-22 12:00', email: null);
    // The week before: one new.
    figuresBooking('confirmed', '2026-10-14 12:00', email: 'eve@example.com');

    expect(figureAt(figuresProp($this), 'tiles.new_customers'))->toBe(['value' => 2, 'previous' => 1, 'change' => ['kind' => 'up', 'percent' => 100], 'returning' => 1]);
});

it('reads occupancy as the counted bookings\' minutes over the hours offered less closures, with both amounts', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    WeeklyHours::week('tenant_luna', $elena, [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00'], [4, '09:00', '17:00'], [5, '09:00', '17:00']]);
    // An hour off Elena's Wednesday and the whole Friday closed for the business; Nikos has no
    // hours.
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => '2026-10-21', 'start_time' => '12:00', 'end_time' => '13:00']);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => '2026-10-23']);

    // 45 minutes on Elena and 60 on Nikos are filled; the cancelled hour is not. Nikos offers no
    // hours, so his hour is outside them and offered as well as filled.
    figuresBooking('confirmed', '2026-10-20 10:00');
    figuresBooking('completed', '2026-10-22 10:00', items: [['Colour', 'Nikos Georgiou', 60, 5000]]);
    figuresBooking('cancelled', '2026-10-22 12:00', items: [['Colour', 'Elena Papadaki', 60, 5000]]);
    // The week before: an hour of 40.
    figuresBooking('confirmed', '2026-10-13 10:00', items: [['Colour', 'Elena Papadaki', 60, 5000]]);

    expect(figureAt(figuresProp($this), 'tiles.occupancy'))->toBe([
        'value' => 5.5, 'previous' => 2.5, 'change' => ['kind' => 'up', 'percent' => 119],
        'filled_minutes' => 105, 'offered_minutes' => 1920,
    ]);
});

it('reads occupancy with no hours offered as no percentage and nothing to compare, never a division by zero', function () {
    expect(figureAt(figuresProp($this), 'tiles.occupancy'))->toBe([
        'value' => null, 'previous' => null, 'change' => ['kind' => 'none', 'percent' => null],
        'filled_minutes' => 0, 'offered_minutes' => 0,
    ]);

    // A booking where no hours are offered is offered as well as filled: all of it, never more.
    figuresBooking('confirmed', '2026-10-20 10:00');

    expect(figureAt(figuresProp($this), 'tiles.occupancy'))->toBe([
        'value' => 100.0, 'previous' => null, 'change' => ['kind' => 'none', 'percent' => null],
        'filled_minutes' => 45, 'offered_minutes' => 45,
    ]);
});

it('keeps every prop Home had but the old week\'s figures, which the range figures replace, and carries none of them to the staff role, sent on to the Calendar', function () {
    $this->actingAs(figuresOwner())->get(tenantUrl('luna', '/admin?range=month'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')
            ->missing('figures')->has('days', 7)->has('next_up')->has('awaiting')->has('activity')->has('members')->has('setup')->has('range_figures'));

    $staff = User::query()->findOrFail(addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki'));
    $this->actingAs($staff)->get(tenantUrl('luna', '/admin?range=month'))->assertRedirect(tenantUrl('luna', '/admin/calendar'));
});
