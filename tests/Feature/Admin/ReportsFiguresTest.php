<?php

use App\Application\Clock;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;
use Tests\Support\WeeklyHours;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * Reports' six tiles: the range Reports' Range names, its words in the address, and the tiles under
 * the Staff and the Service filters, computed by the same classes as Home's (FigureRange,
 * RangeFigureRecords); then every card below them under the same filters, Over time's buckets, and
 * Cancelled by read from the trail.
 *
 * The clock stands on Wednesday 2026-10-21 at 08:00 in Athens: this week is the 19th to the
 * 25th, the week before the 12th to the 18th. Every member's hours are cleared first and given
 * here: Elena works Monday to Friday 09:00 to 17:00 (2400 minutes a week), Nikos Monday and
 * Tuesday 10:00 to 14:00 (480). Haircut is Elena's, Colour both members', Beard trim Nikos's.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-10-21 08:00', 'Europe/Athens'));
    WeeklyHours::clear('tenant_luna');
    WeeklyHours::week('tenant_luna', LunaFixture::id('staff', 'Elena Papadaki'), [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00'], [4, '09:00', '17:00'], [5, '09:00', '17:00']]);
    WeeklyHours::week('tenant_luna', LunaFixture::id('staff', 'Nikos Georgiou'), [[1, '10:00', '14:00'], [2, '10:00', '14:00']]);
    DB::table('tenant_luna.service_staff')->delete();

    foreach ([['Haircut', 'Elena Papadaki'], ['Colour', 'Elena Papadaki'], ['Colour', 'Nikos Georgiou'], ['Beard trim', 'Nikos Georgiou']] as [$service, $member]) {
        DB::table('tenant_luna.service_staff')->insert(['service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', $member)]);
    }
});

function tilesOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * A booking of luna written straight to the rows: its items one after another from the Athens
 * start given, each a service on a member for the minutes and the price given, the total their sum.
 *
 * @param  list<array{0: string, 1: string, 2: int, 3: int}>  $items  service name, member name, minutes, price
 */
function tilesBooking(string $status, string $start, array $items, string $email = 'casey@example.com'): void
{
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status,
        'customer_name' => 'Casey Customer', 'customer_email' => $email,
        'total_minor' => array_sum(array_map(fn (array $item): int => $item[3], $items)), 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    $from = LunaFixture::at($start);

    foreach ($items as $sort => [$service, $staff, $minutes, $price]) {
        $to = $from->add(new DateInterval(sprintf('PT%dM', $minutes)));
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $service), 'staff_id' => LunaFixture::id('staff', $staff),
            'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
            'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
            'price_minor' => $price, 'blocks' => false,
        ]);
        $from = $to;
    }
}

/**
 * The week's bookings every filter case reads: a two-service booking on two members, Haircut on
 * Elena then Colour on Nikos, a Haircut on Elena, a cancelled Colour on Nikos, a no-show
 * Haircut on Elena; the week before a Colour on Nikos for ann; in September a Haircut on Elena
 * for bea.
 */
function tilesWeek(): void
{
    tilesBooking('confirmed', '2026-10-20 10:00', [['Haircut', 'Elena Papadaki', 45, 3500], ['Colour', 'Nikos Georgiou', 60, 6000]], 'ann@example.com'); // B1
    tilesBooking('completed', '2026-10-21 10:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'bea@example.com');                                      // B2
    tilesBooking('cancelled', '2026-10-22 10:00', [['Colour', 'Nikos Georgiou', 60, 6000]], 'cleo@example.com');                                      // B3
    tilesBooking('no_show', '2026-10-22 12:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'dora@example.com');                                       // B4
    tilesBooking('confirmed', '2026-10-13 10:00', [['Colour', 'Nikos Georgiou', 60, 6000]], 'ann@example.com');                                       // B5
    tilesBooking('completed', '2026-09-10 10:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'bea@example.com');                                      // B6
}

/**
 * The Reports page's props as the owner reads them for the query given.
 *
 * @return array<string, mixed>
 */
function tilesProps(TestCase $test, string $query = ''): array
{
    $props = data_get($test->actingAs(tilesOwner())->get(tenantUrl('luna', '/admin/reports'.$query))->assertOk()->viewData('page'), 'props');
    assert(is_array($props));

    /** @var array<string, mixed> $props */
    return $props;
}

/**
 * The six tiles reduced to what a filter case checks: each value, the previous where it has
 * one, the returning customers and the two minute counts.
 *
 * @return array<string, mixed>
 */
function tilesRead(TestCase $test, string $query): array
{
    $tiles = data_get(tilesProps($test, $query), 'figures.tiles');

    return [
        'bookings' => data_get($tiles, 'bookings.value'), 'previous' => data_get($tiles, 'bookings.previous'),
        'revenue' => data_get($tiles, 'revenue.value.minor'), 'cancelled' => data_get($tiles, 'cancelled.value'), 'no_shows' => data_get($tiles, 'no_shows.value'),
        'new' => data_get($tiles, 'new_customers.value'), 'new_before' => data_get($tiles, 'new_customers.previous'), 'returning' => data_get($tiles, 'new_customers.returning'),
        'filled' => data_get($tiles, 'occupancy.filled_minutes'), 'offered' => data_get($tiles, 'occupancy.offered_minutes'),
    ];
}

function tilesId(string $table, string $name): string
{
    return (string) LunaFixture::id($table, $name);
}

it('names each of Reports\' words in the address as its range and the period before, an unknown word this week', function (string $query, array $range, array $before) {
    $props = tilesProps($this, $query);

    expect(data_get($props, 'range'))->toBe($range)
        ->and(data_get($props, 'figures.range'))->toBe($range)
        ->and(data_get($props, 'figures.before'))->toBe($before);
})->with([
    'none' => ['', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'], ['from' => '2026-10-12', 'to' => '2026-10-18']],
    'today' => ['?range=today', ['word' => 'today', 'from' => '2026-10-21', 'to' => '2026-10-21'], ['from' => '2026-10-20', 'to' => '2026-10-20']],
    'yesterday' => ['?range=yesterday', ['word' => 'yesterday', 'from' => '2026-10-20', 'to' => '2026-10-20'], ['from' => '2026-10-19', 'to' => '2026-10-19']],
    'the last 7 days' => ['?range=last_7_days', ['word' => 'last_7_days', 'from' => '2026-10-15', 'to' => '2026-10-21'], ['from' => '2026-10-08', 'to' => '2026-10-14']],
    'the last 30 days' => ['?range=last_30_days', ['word' => 'last_30_days', 'from' => '2026-09-22', 'to' => '2026-10-21'], ['from' => '2026-08-23', 'to' => '2026-09-21']],
    'this month' => ['?range=month', ['word' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-31'], ['from' => '2026-09-01', 'to' => '2026-09-30']],
    'last month' => ['?range=last_month', ['word' => 'last_month', 'from' => '2026-09-01', 'to' => '2026-09-30'], ['from' => '2026-08-01', 'to' => '2026-08-31']],
    'this year' => ['?range=year', ['word' => 'year', 'from' => '2026-01-01', 'to' => '2026-12-31'], ['from' => '2025-01-01', 'to' => '2025-12-31']],
    'a custom range' => ['?range=custom&from=2026-10-05&to=2026-10-14', ['word' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-14'], ['from' => '2026-09-25', 'to' => '2026-10-04']],
    'an unknown word' => ['?range=decade', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'], ['from' => '2026-10-12', 'to' => '2026-10-18']],
    'the old preset word' => ['?preset=month', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'], ['from' => '2026-10-12', 'to' => '2026-10-18']],
]);

it('reads a custom range with the To before the From, a missing end or more than 366 days as this week', function (string $query) {
    expect(data_get(tilesProps($this, $query), 'range'))->toBe(['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25']);
})->with([
    'the To before the From' => ['?range=custom&from=2026-10-14&to=2026-10-05'],
    'no To' => ['?range=custom&from=2026-10-05'],
    'no From' => ['?range=custom&to=2026-10-05'],
    '367 days' => ['?range=custom&from=2026-01-01&to=2027-01-02'],
]);

it('takes a custom range of 366 days', function () {
    expect(data_get(tilesProps($this, '?range=custom&from=2026-01-01&to=2027-01-01'), 'range'))->toBe(['word' => 'custom', 'from' => '2026-01-01', 'to' => '2027-01-01']);
});

it('reads the six tiles with no filter as Home reads them', function () {
    tilesWeek();

    // B1 and B2; 3500 + 6000 + 3500; B3; B4; ann and bea both booked before; 45 + 60 + 45 of 2400 +
    // 480.
    expect(tilesRead($this, ''))->toBe(['bookings' => 2, 'previous' => 1, 'revenue' => 13000, 'cancelled' => 1, 'no_shows' => 1, 'new' => 0, 'new_before' => 1, 'returning' => 2, 'filled' => 150, 'offered' => 2880]);
});

it('counts under a Staff filter only what matches: a booking with an item of the member, the items\' own prices and minutes, the member\'s hours, the customers of those bookings', function () {
    tilesWeek();

    // Elena: B1 and B2 by their Haircuts, 3500 each; nothing before; ann and bea booked before, so
    // returning.
    expect(tilesRead($this, '?staff='.tilesId('staff', 'Elena Papadaki')))->toBe(['bookings' => 2, 'previous' => 0, 'revenue' => 7000, 'cancelled' => 0, 'no_shows' => 1, 'new' => 0, 'new_before' => 0, 'returning' => 2, 'filled' => 90, 'offered' => 2400])
        // Nikos: B1 by its Colour, 6000; before B5, ann's first booking ever, so new then.
        ->and(tilesRead($this, '?staff='.tilesId('staff', 'Nikos Georgiou')))->toBe(['bookings' => 1, 'previous' => 1, 'revenue' => 6000, 'cancelled' => 1, 'no_shows' => 0, 'new' => 0, 'new_before' => 1, 'returning' => 1, 'filled' => 60, 'offered' => 480]);
});

it('counts under a Service filter the bookings holding that service, its items\' prices and minutes, over the hours of the members who perform it', function () {
    tilesWeek();

    // Colour: B1 by its Colour on Nikos; offered by Elena and Nikos, who both perform it.
    expect(tilesRead($this, '?service='.tilesId('services', 'Colour')))->toBe(['bookings' => 1, 'previous' => 1, 'revenue' => 6000, 'cancelled' => 1, 'no_shows' => 0, 'new' => 0, 'new_before' => 1, 'returning' => 1, 'filled' => 60, 'offered' => 2880])
        // Haircut: B1 and B2; offered by Elena alone.
        ->and(tilesRead($this, '?service='.tilesId('services', 'Haircut')))->toBe(['bookings' => 2, 'previous' => 0, 'revenue' => 7000, 'cancelled' => 0, 'no_shows' => 1, 'new' => 0, 'new_before' => 0, 'returning' => 2, 'filled' => 90, 'offered' => 2400])
        // Bridal trial: nobody performs it, nothing booked.
        ->and(tilesRead($this, '?service='.tilesId('services', 'Bridal trial')))->toBe(['bookings' => 0, 'previous' => 0, 'revenue' => 0, 'cancelled' => 0, 'no_shows' => 0, 'new' => 0, 'new_before' => 0, 'returning' => 0, 'filled' => 0, 'offered' => 0]);
});

it('counts under both filters the items that are that service on that member, over the member\'s hours', function () {
    tilesWeek();

    $elena = tilesId('staff', 'Elena Papadaki');
    $nikos = tilesId('staff', 'Nikos Georgiou');
    $colour = tilesId('services', 'Colour');

    // B1's Colour is on Nikos, not Elena: nothing of Elena's is Colour.
    expect(tilesRead($this, "?staff={$elena}&service={$colour}"))->toBe(['bookings' => 0, 'previous' => 0, 'revenue' => 0, 'cancelled' => 0, 'no_shows' => 0, 'new' => 0, 'new_before' => 0, 'returning' => 0, 'filled' => 0, 'offered' => 2400])
        ->and(tilesRead($this, "?staff={$nikos}&service={$colour}"))->toBe(['bookings' => 1, 'previous' => 1, 'revenue' => 6000, 'cancelled' => 1, 'no_shows' => 0, 'new' => 0, 'new_before' => 1, 'returning' => 1, 'filled' => 60, 'offered' => 480]);
});

it('adds the minutes a counted booking holds outside its member\'s hours to the hours offered as well as to the filled, so occupancy never passes 100 percent', function () {
    // Today, Wednesday the 21st, Elena offers 09:00 to 17:00, eight hours: an hour inside them,
    // an hour at 18:00 where she has no shift, and a cancelled hour at 19:00 that counts nowhere.
    tilesBooking('confirmed', '2026-10-21 10:00', [['Haircut', 'Elena Papadaki', 60, 3500]]);
    tilesBooking('completed', '2026-10-21 18:00', [['Haircut', 'Elena Papadaki', 60, 3500]]);
    tilesBooking('cancelled', '2026-10-21 19:00', [['Haircut', 'Elena Papadaki', 60, 3500]]);

    $tiles = data_get(tilesProps($this, '?range=today&staff='.tilesId('staff', 'Elena Papadaki')), 'figures.tiles.occupancy');

    expect(data_get($tiles, 'filled_minutes'))->toBe(120)
        ->and(data_get($tiles, 'offered_minutes'))->toBe(540)
        ->and(data_get($tiles, 'value'))->toBe(22.2);

    // Nikos has no hours on a Wednesday: an hour booked on him is an hour offered and filled.
    tilesBooking('confirmed', '2026-10-21 11:00', [['Beard trim', 'Nikos Georgiou', 60, 2000]]);

    expect(data_get(tilesProps($this, '?range=today'), 'figures.tiles.occupancy'))->toMatchArray(['filled_minutes' => 180, 'offered_minutes' => 600]);
});

it('adds the minutes a counted booking holds inside a closure to the hours offered as well as to the filled', function () {
    // Elena's Wednesday less an hour closed at noon: 420 minutes. An hour inside her hours, an
    // hour inside the closure, and an hour from 16:30 of which half is past her shift.
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => LunaFixture::id('staff', 'Elena Papadaki'), 'date' => '2026-10-21', 'start_time' => '12:00', 'end_time' => '13:00']);
    tilesBooking('confirmed', '2026-10-21 10:00', [['Haircut', 'Elena Papadaki', 60, 3500]]);
    tilesBooking('confirmed', '2026-10-21 12:00', [['Haircut', 'Elena Papadaki', 60, 3500]]);
    tilesBooking('completed', '2026-10-21 16:30', [['Haircut', 'Elena Papadaki', 60, 3500]]);

    expect(data_get(tilesProps($this, '?range=today&staff='.tilesId('staff', 'Elena Papadaki')), 'figures.tiles.occupancy'))
        ->toMatchArray(['filled_minutes' => 180, 'offered_minutes' => 420 + 60 + 30]);
});

it('reads Occupancy by staff per member in the catalogue\'s order, the booked minutes over the available under the same rule, under the filters', function () {
    tilesWeek();
    // Nikos works no Thursday: half an hour on him then is booked and available both.
    tilesBooking('confirmed', '2026-10-22 15:00', [['Beard trim', 'Nikos Georgiou', 30, 2000]], 'eve@example.com');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $row = fn (int $id, string $name, int $booked, int $available, ?float $occupancy): array => ['id' => $id, 'name' => $name, 'booked_minutes' => $booked, 'available_minutes' => $available, 'occupancy' => $occupancy];

    // Elena: B1's Haircut and B2, 90 of 2400; Nikos: B1's Colour and the Thursday half hour, 90
    // of 480 + 30. The business's own row offers nothing and holds nothing, so it is left out.
    expect(data_get(tilesProps($this), 'report.occupancy'))->toBe([
        $row($elena, 'Elena Papadaki', 90, 2400, 3.8),
        $row($nikos, 'Nikos Georgiou', 90, 510, 17.6),
    ])
        ->and(data_get(tilesProps($this, '?staff='.$nikos), 'report.occupancy'))->toBe([$row($nikos, 'Nikos Georgiou', 90, 510, 17.6)])
        // Colour: both perform it; only B1's Colour on Nikos is booked.
        ->and(data_get(tilesProps($this, '?service='.LunaFixture::id('services', 'Colour')), 'report.occupancy'))->toBe([
            $row($elena, 'Elena Papadaki', 0, 2400, 0.0),
            $row($nikos, 'Nikos Georgiou', 60, 480, 12.5),
        ])
        // Bridal trial: nobody performs it and nothing is booked.
        ->and(data_get(tilesProps($this, '?service='.LunaFixture::id('services', 'Bridal trial')), 'report.occupancy'))->toBe([]);

    // An inactive member offers nothing, yet a counted booking on them keeps their row.
    DB::table('tenant_luna.staff')->where('id', $nikos)->update(['status' => 'inactive']);

    expect(data_get(tilesProps($this), 'report.occupancy'))->toBe([
        $row($elena, 'Elena Papadaki', 90, 2400, 3.8),
        $row($nikos, 'Nikos Georgiou', 90, 90, 100.0),
    ]);
});

it('answers the filters it read and offers the active members, the business itself left out, and the active services', function () {
    $props = tilesProps($this, '?staff='.tilesId('staff', 'Nikos Georgiou').'&service='.tilesId('services', 'Colour'));

    expect(data_get($props, 'filters'))->toBe(['staff' => LunaFixture::id('staff', 'Nikos Georgiou'), 'service' => LunaFixture::id('services', 'Colour')])
        ->and(data_get($props, 'options.staff'))->toBe([
            ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki'],
            ['id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'name' => 'Nikos Georgiou'],
        ])
        ->and(data_get($props, 'options.services.*.name'))->toBe(['Haircut', 'Beard trim', 'Colour', 'Bridal trial']);
});

it('reads a member or a service it does not offer as all of them', function (callable $query) {
    DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->update(['status' => 'inactive']);
    tilesWeek();

    $asked = $query();
    assert(is_string($asked));
    $props = tilesProps($this, $asked);

    expect(data_get($props, 'filters'))->toBe(['staff' => null, 'service' => null])
        ->and(data_get($props, 'figures.tiles.bookings.value'))->toBe(2);
})->with([
    'an unknown member' => [fn (): string => '?staff=999999&service=999999'],
    'the business itself' => [fn (): string => '?staff='.implicitStaffId('tenant_luna')],
    'an inactive member' => [fn (): string => '?staff='.tilesId('staff', 'Nikos Georgiou')],
    'an inactive service' => [fn (): string => '?service='.tilesId('services', 'Perm')],
    'a member in an array' => [fn (): string => '?staff[]='.tilesId('staff', 'Elena Papadaki')],
    'a word' => [fn (): string => '?staff=elena&service=colour'],
]);

/**
 * A trail row on the booking of the address given, as a transition writes it.
 */
function tilesTrail(string $email, string $actor, string $action): void
{
    DB::table('tenant_luna.audit_log')->insert([
        'booking_id' => DB::table('tenant_luna.bookings')->where('customer_email', $email)->value('id'),
        'actor' => $actor, 'action' => $action, 'payload' => '{}',
    ]);
}

/**
 * Over time's rows reduced to what a case checks: each bucket's dates, bookings and revenue.
 *
 * @return array{unit: mixed, rows: list<string>}
 */
function tilesOverTime(TestCase $test, string $query = ''): array
{
    $over = data_get(tilesProps($test, $query), 'report.over_time');
    $rows = data_get($over, 'rows');
    assert(is_array($rows));

    return [
        'unit' => data_get($over, 'unit'),
        'rows' => array_values(array_map(fn (mixed $row): string => implode(' ', array_map(
            fn (string $key): string => is_scalar($value = data_get($row, $key)) ? (string) $value : '',
            ['from', 'to', 'bookings', 'revenue.currency', 'revenue.minor'],
        )), $rows)),
    ];
}

it('narrows every card and table to the Staff and Service filters: a booking counted where one of its items matches, its revenue those items\' own prices', function () {
    tilesWeek();
    $eur = fn (int $minor): array => [['currency' => 'EUR', 'minor' => $minor]];
    $zero = $eur(0);
    $statuses = fn (array $counts): array => array_map(
        fn (string $status): array => ['status' => $status, 'bookings' => $counts[$status] ?? 0],
        ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'],
    );
    $haircut = LunaFixture::id('services', 'Haircut');
    $colour = LunaFixture::id('services', 'Colour');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $implicit = implicitStaffId('tenant_luna');

    // Elena: B1 by its Haircut; B3 is Nikos's alone.
    $report = data_get(tilesProps($this, '?staff='.$elena), 'report');

    expect(data_get($report, 'statuses'))->toBe($statuses(['confirmed' => 1, 'completed' => 1, 'no_show' => 1]))
        ->and(data_get($report, 'revenue'))->toBe(['confirmed' => $eur(3500), 'completed' => $eur(3500)])
        ->and(data_get($report, 'no_show'))->toBe(['no_show' => 1, 'completed' => 1])
        ->and(data_get($report, 'cancelled_by'))->toBe(['customer' => 0, 'business' => 0, 'unrecorded' => 0])
        // B1's Colour is Nikos's: Colour holds nothing of Elena's.
        ->and(data_get($report, 'services'))->toBe([
            ['id' => $haircut, 'name' => 'Haircut', 'bookings' => 3, 'revenue' => $eur(7000)],
            ['id' => LunaFixture::id('services', 'Beard trim'), 'name' => 'Beard trim', 'bookings' => 0, 'revenue' => $zero],
            ['id' => $colour, 'name' => 'Colour', 'bookings' => 0, 'revenue' => $zero],
            ['id' => LunaFixture::id('services', 'Bridal trial'), 'name' => 'Bridal trial', 'bookings' => 0, 'revenue' => $zero],
        ])
        ->and(data_get($report, 'staff'))->toBe([
            ['id' => $implicit, 'name' => 'Luna Hair Studio', 'bookings' => 0, 'revenue' => $zero],
            ['id' => $elena, 'name' => 'Elena Papadaki', 'bookings' => 3, 'revenue' => $eur(7000)],
            ['id' => $nikos, 'name' => 'Nikos Georgiou', 'bookings' => 0, 'revenue' => $zero],
        ])
        ->and(data_get($report, 'sources.1'))->toBe(['source' => 'embed', 'bookings' => 3, 'revenue' => $eur(7000)])
        ->and(data_get($report, 'channels.5'))->toBe(['channel' => null, 'bookings' => 3, 'revenue' => $eur(7000)]);

    // Colour: B1 by its Colour on Nikos, 6000 of its 9500, and B3, cancelled with no trail.
    $report = data_get(tilesProps($this, '?service='.$colour), 'report');

    expect(data_get($report, 'statuses'))->toBe($statuses(['confirmed' => 1, 'cancelled' => 1]))
        ->and(data_get($report, 'revenue'))->toBe(['confirmed' => $eur(6000), 'completed' => $zero])
        ->and(data_get($report, 'cancelled_by'))->toBe(['customer' => 0, 'business' => 0, 'unrecorded' => 1])
        ->and(data_get($report, 'services.2'))->toBe(['id' => $colour, 'name' => 'Colour', 'bookings' => 2, 'revenue' => $eur(6000)])
        ->and(data_get($report, 'services.0'))->toBe(['id' => $haircut, 'name' => 'Haircut', 'bookings' => 0, 'revenue' => $zero])
        ->and(data_get($report, 'staff.2'))->toBe(['id' => $nikos, 'name' => 'Nikos Georgiou', 'bookings' => 2, 'revenue' => $eur(6000)])
        ->and(data_get($report, 'staff.1'))->toBe(['id' => $elena, 'name' => 'Elena Papadaki', 'bookings' => 0, 'revenue' => $zero]);

    // With neither, the whole range: B1's whole total.
    expect(data_get(tilesProps($this), 'report.revenue.confirmed'))->toBe($eur(9500));
});

it('splits the cancelled bookings by who cancelled them, read from the trail: the customer, or the business by a user or support, and none where no trail says', function () {
    tilesWeek();
    // B3, cleo's, has no trail. Four more cancelled in the week, and one the week before.
    tilesBooking('cancelled', '2026-10-23 10:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'c1@example.com');
    tilesBooking('cancelled', '2026-10-23 11:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'c2@example.com');
    tilesBooking('cancelled', '2026-10-23 12:00', [['Colour', 'Nikos Georgiou', 60, 6000]], 'c3@example.com');
    tilesBooking('cancelled', '2026-10-24 10:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'c4@example.com');
    tilesBooking('cancelled', '2026-10-14 10:00', [['Haircut', 'Elena Papadaki', 45, 3500]], 'c5@example.com');
    tilesTrail('c1@example.com', 'customer', 'held');
    tilesTrail('c1@example.com', 'customer', 'cancelled');
    tilesTrail('c2@example.com', 'customer', 'held');
    tilesTrail('c2@example.com', 'user:'.tilesOwner()->id, 'cancelled');
    tilesTrail('c3@example.com', 'customer', 'released');
    tilesTrail('c4@example.com', 'support:1', 'cancelled');
    tilesTrail('c5@example.com', 'customer', 'cancelled');

    // c1 and c3 the customer's, c2 and c4 the business's, B3 unrecorded; c5 out of the range.
    expect(data_get(tilesProps($this), 'report.cancelled_by'))->toBe(['customer' => 2, 'business' => 2, 'unrecorded' => 1])
        ->and(data_get(tilesProps($this), 'report.statuses.6'))->toBe(['status' => 'cancelled', 'bookings' => 5])
        // Elena: c1, c2, c4.
        ->and(data_get(tilesProps($this, '?staff='.tilesId('staff', 'Elena Papadaki')), 'report.cancelled_by'))->toBe(['customer' => 1, 'business' => 2, 'unrecorded' => 0]);
});

it('draws Over time per day up to 31 days, the tiles\' bookings and revenue in the tenant\'s currency, and under the filters', function () {
    tilesWeek();
    // A confirmed booking in dollars: a booking of the 23rd, no revenue in euros.
    DB::table('tenant_luna.bookings')->where('customer_email', 'cleo@example.com')->update(['status' => 'confirmed', 'currency' => 'USD']);

    expect(tilesOverTime($this))->toBe(['unit' => 'day', 'rows' => [
        '2026-10-19 2026-10-19 0 EUR 0',
        '2026-10-20 2026-10-20 1 EUR 9500',
        '2026-10-21 2026-10-21 1 EUR 3500',
        '2026-10-22 2026-10-22 1 EUR 0',
        '2026-10-23 2026-10-23 0 EUR 0',
        '2026-10-24 2026-10-24 0 EUR 0',
        '2026-10-25 2026-10-25 0 EUR 0',
    ]])
        // The buckets sum to the tiles: 3 bookings, 13000 in euros, the dollars beside it.
        ->and(data_get(tilesProps($this), 'figures.tiles.bookings.value'))->toBe(3)
        ->and(data_get(tilesProps($this), 'figures.tiles.revenue.value.minor'))->toBe(13000)
        ->and(tilesOverTime($this, '?staff='.tilesId('staff', 'Elena Papadaki'))['rows'])->toBe([
            '2026-10-19 2026-10-19 0 EUR 0',
            '2026-10-20 2026-10-20 1 EUR 3500',
            '2026-10-21 2026-10-21 1 EUR 3500',
            '2026-10-22 2026-10-22 0 EUR 0',
            '2026-10-23 2026-10-23 0 EUR 0',
            '2026-10-24 2026-10-24 0 EUR 0',
            '2026-10-25 2026-10-25 0 EUR 0',
        ]);
});

it('draws Over time per week of the tenant\'s week start from 32 days, and per month past 183', function () {
    tilesWeek();

    // Thursday 10 September to Wednesday 21 October, 42 days, on Monday weeks: B6, then B5, then B1
    // and B2.
    expect(tilesOverTime($this, '?range=custom&from=2026-09-10&to=2026-10-21'))->toBe(['unit' => 'week', 'rows' => [
        '2026-09-10 2026-09-13 1 EUR 3500',
        '2026-09-14 2026-09-20 0 EUR 0',
        '2026-09-21 2026-09-27 0 EUR 0',
        '2026-09-28 2026-10-04 0 EUR 0',
        '2026-10-05 2026-10-11 0 EUR 0',
        '2026-10-12 2026-10-18 1 EUR 6000',
        '2026-10-19 2026-10-21 2 EUR 13000',
    ]]);

    DB::table('tenant_luna.settings')->where('id', 1)->update(['start_of_week' => 7]);

    expect(tilesOverTime($this, '?range=custom&from=2026-09-10&to=2026-10-21')['rows'][0])->toBe('2026-09-10 2026-09-12 1 EUR 3500')
        ->and(tilesOverTime($this, '?range=custom&from=2026-09-10&to=2026-10-21')['rows'][6])->toBe('2026-10-18 2026-10-21 2 EUR 13000');

    $year = tilesOverTime($this, '?range=year');

    expect($year['unit'])->toBe('month')
        ->and($year['rows'])->toHaveCount(12)
        ->and($year['rows'][0])->toBe('2026-01-01 2026-01-31 0 EUR 0')
        ->and($year['rows'][8])->toBe('2026-09-01 2026-09-30 1 EUR 3500')
        ->and($year['rows'][9])->toBe('2026-10-01 2026-10-31 3 EUR 19000');
});

it('reads no Top table for Reports, which shows none', function () {
    tilesWeek();
    DB::enableQueryLog();
    tilesProps($this);
    $statements = array_column(DB::getQueryLog(), 'query');

    expect(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'order by minor desc')))->toBe([]);
});

it('reads the range, the filters, the tiles and the tables again by a partial reload of those alone', function () {
    tilesWeek();
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $partial = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Reports', 'X-Inertia-Partial-Data' => 'range,filters,figures,report'];

    $this->actingAs(tilesOwner())->get(tenantUrl('luna', '/admin/reports?range=last_month&staff='.tilesId('staff', 'Elena Papadaki')), $partial)
        ->assertOk()
        ->assertJsonPath('props.range', ['word' => 'last_month', 'from' => '2026-09-01', 'to' => '2026-09-30'])
        ->assertJsonPath('props.filters.staff', LunaFixture::id('staff', 'Elena Papadaki'))
        ->assertJsonPath('props.figures.tiles.bookings.value', 1)
        ->assertJsonMissingPath('props.options')
        ->assertJsonMissingPath('props.nav');
});
