<?php

use App\Application\Clock;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;
use Tests\TestCase;

pest()->use(RefreshDatabase::class);

/*
 * The Reports screen: the range a preset or a custom pair of dates names, read in luna's zone
 * over each booking's first start, the To date inclusive and a bad custom range reading as this
 * week; and every figure over it, the count per status, the revenue of confirmed and of
 * completed per currency, the no-show rate's two counts, and the four breakdowns, each held
 * against a sum worked by hand in the comments beside it.
 *
 * The bookings are written straight to the rows at literal dates, so the clock is pinned first:
 * FixtureDay's clock stands on Monday 2026-11-02 at 08:00 in Athens, so this week is the 2nd to
 * the 8th; the range cases pin a clock of their own in October, when Athens is three hours ahead
 * of UTC and a booking just after the tenant's midnight is still the day before in UTC.
 */

beforeEach(function () {
    $this->withoutVite();
    seedDemo();
    FixtureDay::pinClock();
});

function reportsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

/**
 * A booking of luna written straight to the rows: one item per entry, in order, each a service
 * by name, a member by name or null for the implicit member, an Athens start and a price; the
 * booking's total is the sum of its items' prices, as the write transaction makes it.
 *
 * @param  non-empty-list<array{0: string, 1: string|null, 2: string, 3: int}>  $items
 */
function reportsBooking(string $status, string $currency, string $source, ?string $channel, array $items): string
{
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => $source, 'channel' => $channel,
        'uuid' => $uuid, 'status' => $status, 'customer_name' => 'Casey Customer', 'customer_email' => 'casey@example.com',
        'total_minor' => array_sum(array_column($items, 3)), 'currency' => $currency,
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);

    foreach ($items as $sort => [$service, $staff, $start, $price]) {
        $from = LunaFixture::at($start);
        $to = $from->add(new DateInterval('PT45M'));
        DB::table('tenant_luna.booking_items')->insert([
            'booking_id' => $id, 'sort' => $sort,
            'service_id' => LunaFixture::id('services', $service),
            'staff_id' => $staff === null ? implicitStaffId('tenant_luna') : LunaFixture::id('staff', $staff),
            'start_utc' => LunaFixture::instant($from), 'end_utc' => LunaFixture::instant($to),
            'block_start_utc' => LunaFixture::instant($from), 'block_end_utc' => LunaFixture::instant($to),
            'price_minor' => $price, 'blocks' => false,
        ]);
    }

    return $uuid;
}

/** A confirmed Haircut on Elena at each Athens start given, one booking per start. */
function reportsAt(string ...$starts): void
{
    foreach ($starts as $start) {
        reportsBooking('confirmed', 'EUR', 'embed', null, [['Haircut', 'Elena Papadaki', $start, 3500]]);
    }
}

/**
 * A revenue line as the screen receives it.
 *
 * @return array{currency: string, minor: int}
 */
function reportsMoney(string $currency, int $minor): array
{
    return ['currency' => $currency, 'minor' => $minor];
}

/**
 * What luna's owner is shown for a query: the range answered and the count of confirmed bookings
 * in it, every booking the range cases write being confirmed.
 *
 * @return array{component: mixed, range: mixed, confirmed: mixed}
 */
function reportsFor(TestCase $test, string $query = ''): array
{
    $page = Assert::fromTestResponse($test->actingAs(reportsOwner())->get(tenantUrl('luna', '/admin/reports'.$query))->assertOk())->toArray();

    return ['component' => $page['component'] ?? null, 'range' => data_get($page, 'props.range'), 'confirmed' => data_get($page, 'props.report.statuses.3.bookings')];
}

/**
 * The answer reportsFor expects for a range and a count.
 *
 * @return array{component: string, range: array{word: string, from: string, to: string}, confirmed: int}
 */
function reportsRange(string $word, string $from, string $to, int $confirmed): array
{
    return ['component' => 'Reports', 'range' => ['word' => $word, 'from' => $from, 'to' => $to], 'confirmed' => $confirmed];
}

/**
 * The nine statuses with the counts given, zero for every other, in the machine's order.
 *
 * @param  array<string, int>  $counts
 * @return list<array{status: string, bookings: int}>
 */
function reportsStatuses(array $counts = []): array
{
    return array_map(
        fn (string $status): array => ['status' => $status, 'bookings' => $counts[$status] ?? 0],
        ['pending', 'awaiting_approval', 'awaiting_payment', 'confirmed', 'completed', 'no_show', 'cancelled', 'rejected', 'expired'],
    );
}

it('reads this week by default, the tenant\'s week from its week start, and states the range it answered', function () {
    expect(reportsFor($this))->toBe(reportsRange('week', '2026-11-02', '2026-11-08', 0));
});

it('resolves the three presets and the custom range in the tenant\'s zone over each booking\'s first start, the To date inclusive', function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-07 12:00', 'Europe/Athens'));

    reportsAt(
        '2026-09-30 23:30', // 20:30 UTC on the 30th: before the month
        '2026-10-01 00:30', // 21:30 UTC on 30 September: the month's first booking
        '2026-10-04 23:30', // 20:30 UTC on the 4th: the Sunday before the week
        '2026-10-05 00:30', // 21:30 UTC on the 4th: the week's first booking, the 5th in Athens
        '2026-10-12 00:30', // 21:30 UTC on the 11th: the Monday after the week, the 12th in Athens
        '2026-10-31 23:30', // 21:30 UTC on the 31st, Athens two hours ahead after the 25th: the month's last
        '2026-11-01 00:30', // 22:30 UTC on 31 October: after the month
    );
    // A chain whose first start is the week's last evening and whose second item is the next day:
    // in.
    reportsBooking('confirmed', 'EUR', 'embed', null, [['Haircut', 'Elena Papadaki', '2026-10-11 23:30', 3500], ['Haircut', 'Elena Papadaki', '2026-10-12 00:15', 3500]]);
    // A chain whose first start is before the week and whose second item is inside it: out.
    reportsBooking('confirmed', 'EUR', 'embed', null, [['Haircut', 'Elena Papadaki', '2026-10-04 23:45', 3500], ['Haircut', 'Elena Papadaki', '2026-10-05 00:30', 3500]]);

    expect(reportsFor($this))->toBe(reportsRange('week', '2026-10-05', '2026-10-11', 2))
        ->and(reportsFor($this, '?range=week'))->toBe(reportsRange('week', '2026-10-05', '2026-10-11', 2))
        ->and(reportsFor($this, '?range=month'))->toBe(reportsRange('month', '2026-10-01', '2026-10-31', 7))
        ->and(reportsFor($this, '?range=custom&from=2026-10-05&to=2026-10-11'))->toBe(reportsRange('custom', '2026-10-05', '2026-10-11', 2))
        ->and(reportsFor($this, '?range=custom&from=2026-10-11&to=2026-10-11'))->toBe(reportsRange('custom', '2026-10-11', '2026-10-11', 1))
        ->and(reportsFor($this, '?range=custom&from=2026-10-12&to=2026-10-12'))->toBe(reportsRange('custom', '2026-10-12', '2026-10-12', 1))
        ->and(reportsFor($this, '?range=custom&from=2026-10-04&to=2026-10-04'))->toBe(reportsRange('custom', '2026-10-04', '2026-10-04', 2))
        ->and(reportsFor($this, '?range=custom&from=2026-09-01&to=2026-11-30'))->toBe(reportsRange('custom', '2026-09-01', '2026-11-30', 9));
});

it('reads a malformed or inverted custom range, or an unknown preset, as this week, and a preset\'s own range whatever dates ride beside it', function (string $query) {
    app()->instance(Clock::class, FixedClock::at('2026-10-07 12:00', 'Europe/Athens'));
    reportsAt('2026-10-05 00:30', '2026-10-06 10:00', '2026-10-20 10:00');

    expect(reportsFor($this, $query))->toBe(reportsRange('week', '2026-10-05', '2026-10-11', 2));
})->with([
    'inverted' => ['?range=custom&from=2026-10-20&to=2026-10-05'],
    'an impossible date' => ['?range=custom&from=2026-02-30&to=2026-10-20'],
    'a date of the wrong shape' => ['?range=custom&from=20261005&to=2026-10-20'],
    'no To' => ['?range=custom&from=2026-10-01'],
    'no dates' => ['?range=custom'],
    'an unknown word' => ['?range=decade&from=2026-10-01&to=2026-10-31'],
    'a word in an array' => ['?range[]=month'],
    'the old preset word' => ['?preset=month'],
    'week with dates beside it' => ['?range=week&from=2026-10-01&to=2026-10-31'],
]);

it('reads the calendar\'s first day as a custom From like any other east of UTC, bounding nothing, and text no column can compare as no value', function (string $query, string $preset, string $from, string $to, int $confirmed) {
    app()->instance(Clock::class, FixedClock::at('2026-10-07 12:00', 'Europe/Athens'));
    reportsAt('2026-10-06 10:00');

    // luna's zone is Athens, so the first day's midnight is an instant before the first the
    // database can spell: the range starts at that instant instead, before every booking.
    expect(reportsFor($this, $query))->toBe(reportsRange($preset, $from, $to, $confirmed));
})->with([
    'the first day' => ['?range=custom&from=0001-01-01&to=0001-01-08', 'custom', '0001-01-01', '0001-01-08', 0],
    'the first day to this week, past the 366 days a custom range holds' => ['?range=custom&from=0001-01-01&to=2026-10-11', 'week', '2026-10-05', '2026-10-11', 1],
    'a word holding a NUL' => ['?range=mo%00nth', 'week', '2026-10-05', '2026-10-11', 1],
    'a From that is not UTF-8' => ['?range=custom&from=%FF&to=2026-10-11', 'week', '2026-10-05', '2026-10-11', 1],
]);

it('starts the week on the tenant\'s week start', function () {
    app()->instance(Clock::class, FixedClock::at('2026-10-07 12:00', 'Europe/Athens'));
    DB::table('tenant_luna.settings')->where('id', 1)->update(['start_of_week' => 7]);
    reportsAt('2026-10-03 23:30', '2026-10-04 00:30', '2026-10-10 23:30', '2026-10-11 00:30');

    expect(reportsFor($this))->toBe(reportsRange('week', '2026-10-04', '2026-10-10', 2));
});

it('counts every status, sums the revenue of confirmed and of completed per currency, gives the no-show rate\'s counts and breaks the range down by service, staff, source and channel', function () {
    // Every status, two currencies, two services (Haircut, Colour), two members (Elena and the
    // implicit member, luna's own row), the three sources and two channels, all in the week.
    reportsBooking('confirmed', 'EUR', 'hosted', 'instagram', [['Haircut', 'Elena Papadaki', '2026-11-03 10:00', 3500]]);                                   // B1
    reportsBooking('confirmed', 'USD', 'embed', null, [['Colour', null, '2026-11-04 10:00', 6000]]);                                                       // B2
    reportsBooking('completed', 'EUR', 'hosted', 'qr', [['Haircut', 'Elena Papadaki', '2026-11-02 09:00', 3500], ['Colour', null, '2026-11-02 09:45', 6000]]); // B3
    reportsBooking('completed', 'EUR', 'admin', null, [['Haircut', null, '2026-11-05 10:00', 3500]]);                                                      // B4
    reportsBooking('completed', 'USD', 'hosted', 'instagram', [['Colour', 'Elena Papadaki', '2026-11-06 10:00', 6000]]);                                    // B5
    reportsBooking('no_show', 'EUR', 'embed', null, [['Haircut', 'Elena Papadaki', '2026-11-06 12:00', 3500]]);                                             // B6
    reportsBooking('pending', 'EUR', 'embed', null, [['Colour', 'Elena Papadaki', '2026-11-07 10:00', 6000]]);                                              // B7
    reportsBooking('awaiting_approval', 'EUR', 'hosted', 'instagram', [['Haircut', 'Elena Papadaki', '2026-11-07 11:00', 3500]]);                           // B8
    reportsBooking('awaiting_payment', 'USD', 'hosted', 'qr', [['Colour', null, '2026-11-07 12:00', 6000]]);                                                // B9
    reportsBooking('cancelled', 'EUR', 'admin', null, [['Haircut', 'Elena Papadaki', '2026-11-08 10:00', 3500]]);                                           // B10
    reportsBooking('rejected', 'EUR', 'embed', null, [['Colour', 'Elena Papadaki', '2026-11-08 11:00', 6000]]);                                             // B11
    reportsBooking('expired', 'EUR', 'hosted', null, [['Haircut', null, '2026-11-08 12:00', 3500]]);                                                        // B12
    // Outside the week on either side: counted nowhere.
    reportsBooking('confirmed', 'EUR', 'hosted', 'instagram', [['Haircut', 'Elena Papadaki', '2026-11-01 23:30', 3500]]);
    reportsBooking('completed', 'USD', 'embed', null, [['Colour', 'Elena Papadaki', '2026-11-09 00:30', 6000]]);

    $haircut = LunaFixture::id('services', 'Haircut');
    $colour = LunaFixture::id('services', 'Colour');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $implicit = implicitStaffId('tenant_luna');
    $none = [reportsMoney('EUR', 0)];

    $this->actingAs(reportsOwner())->get(tenantUrl('luna', '/admin/reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports')
            ->where('range', ['word' => 'week', 'from' => '2026-11-02', 'to' => '2026-11-08'])
            // One of each but confirmed and completed.
            ->where('report.statuses', reportsStatuses([
                'pending' => 1, 'awaiting_approval' => 1, 'awaiting_payment' => 1, 'confirmed' => 2, 'completed' => 3,
                'no_show' => 1, 'cancelled' => 1, 'rejected' => 1, 'expired' => 1,
            ]))
            // Confirmed: B1 3500 EUR, B2 6000 USD. Completed: B3 9500 + B4 3500 EUR, B5 6000 USD.
            ->where('report.revenue', [
                'confirmed' => [reportsMoney('EUR', 3500), reportsMoney('USD', 6000)],
                'completed' => [reportsMoney('EUR', 13000), reportsMoney('USD', 6000)],
            ])
            // B6 over B3.
            ->where('report.no_show', ['no_show' => 1, 'completed' => 3])
            // Haircut: B1 B3 B4 B6 B8 B10 B12, revenue B1 3500 + B3's Haircut 3500 + B4 3500 EUR.
            // Colour: B2 B3 B5 B7 B9 B11, revenue B3's Colour 6000 EUR, B2 6000 + B5 6000 USD.
            // Beard trim and Bridal trial, active and unbooked, at zero; Perm, inactive and
            // unbooked, absent.
            ->where('report.services', [
                ['id' => $haircut, 'name' => 'Haircut', 'bookings' => 7, 'revenue' => [reportsMoney('EUR', 10500)]],
                ['id' => LunaFixture::id('services', 'Beard trim'), 'name' => 'Beard trim', 'bookings' => 0, 'revenue' => $none],
                ['id' => $colour, 'name' => 'Colour', 'bookings' => 6, 'revenue' => [reportsMoney('EUR', 6000), reportsMoney('USD', 12000)]],
                ['id' => LunaFixture::id('services', 'Bridal trial'), 'name' => 'Bridal trial', 'bookings' => 0, 'revenue' => $none],
            ])
            // The implicit member, named as the business: B2 B3 B4 B9 B12, revenue B3's Colour 6000
            // + B4 3500 EUR, B2 6000 USD. Elena: B1 B3 B5 B6 B7 B8 B10 B11, revenue B1 3500 + B3's
            // Haircut 3500 EUR, B5 6000 USD. Nikos, active and unbooked, at zero.
            ->where('report.staff', [
                ['id' => $implicit, 'name' => 'Luna Hair Studio', 'bookings' => 5, 'revenue' => [reportsMoney('EUR', 9500), reportsMoney('USD', 6000)]],
                ['id' => $elena, 'name' => 'Elena Papadaki', 'bookings' => 8, 'revenue' => [reportsMoney('EUR', 7000), reportsMoney('USD', 6000)]],
                ['id' => $nikos, 'name' => 'Nikos Georgiou', 'bookings' => 0, 'revenue' => $none],
            ])
            // Hosted: B1 B3 B5 B8 B9 B12, revenue B1 3500 + B3 9500 EUR, B5 6000 USD. Embed: B2 B6
            // B7 B11, revenue B2 6000 USD alone. Admin: B4 B10, revenue B4 3500 EUR.
            ->where('report.sources', [
                ['source' => 'hosted', 'bookings' => 6, 'revenue' => [reportsMoney('EUR', 13000), reportsMoney('USD', 6000)]],
                ['source' => 'embed', 'bookings' => 4, 'revenue' => [reportsMoney('USD', 6000)]],
                ['source' => 'admin', 'bookings' => 2, 'revenue' => [reportsMoney('EUR', 3500)]],
            ])
            // Instagram: B1 B5 B8, revenue B1 3500 EUR, B5 6000 USD. QR: B3 B9, revenue B3 9500
            // EUR. None: B2 B4 B6 B7 B10 B11 B12, revenue B4 3500 EUR, B2 6000 USD.
            ->where('report.channels', [
                ['channel' => 'instagram', 'bookings' => 3, 'revenue' => [reportsMoney('EUR', 3500), reportsMoney('USD', 6000)]],
                ['channel' => 'facebook', 'bookings' => 0, 'revenue' => $none],
                ['channel' => 'google', 'bookings' => 0, 'revenue' => $none],
                ['channel' => 'qr', 'bookings' => 2, 'revenue' => [reportsMoney('EUR', 9500)]],
                ['channel' => 'other', 'bookings' => 0, 'revenue' => $none],
                ['channel' => null, 'bookings' => 7, 'revenue' => [reportsMoney('EUR', 3500), reportsMoney('USD', 6000)]],
            ]));
});

it('reads zero everywhere over a range holding no booking, every revenue line zero in the tenant\'s currency', function () {
    reportsBooking('confirmed', 'USD', 'hosted', 'instagram', [['Haircut', 'Elena Papadaki', '2026-11-20 10:00', 3500]]);
    $none = [reportsMoney('EUR', 0)];

    $this->actingAs(reportsOwner())->get(tenantUrl('luna', '/admin/reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports')
            ->where('report.statuses', reportsStatuses())
            ->where('report.revenue', ['confirmed' => $none, 'completed' => $none])
            ->where('report.no_show', ['no_show' => 0, 'completed' => 0])
            ->where('report.services', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['Haircut', 'Beard trim', 'Colour', 'Bridal trial']
                && $rows->every(fn (array $row): bool => $row['bookings'] === 0 && $row['revenue'] === $none))
            ->where('report.staff', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['Luna Hair Studio', 'Elena Papadaki', 'Nikos Georgiou']
                && $rows->every(fn (array $row): bool => $row['bookings'] === 0 && $row['revenue'] === $none))
            ->where('report.sources', array_map(fn (string $source): array => ['source' => $source, 'bookings' => 0, 'revenue' => $none], ['hosted', 'embed', 'admin']))
            ->where('report.channels', array_map(fn (?string $channel): array => ['channel' => $channel, 'bookings' => 0, 'revenue' => $none], ['instagram', 'facebook', 'google', 'qr', 'other', null])));
});

it('lists an inactive service or member only where it holds an item in the range, and counts a booking once however many of its items a row holds', function () {
    DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->update(['status' => 'inactive']);
    reportsBooking('completed', 'EUR', 'embed', null, [['Perm', 'Elena Papadaki', '2026-11-03 10:00', 8000], ['Perm', 'Elena Papadaki', '2026-11-03 10:45', 8000]]);

    $this->actingAs(reportsOwner())->get(tenantUrl('luna', '/admin/reports'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.services', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['Haircut', 'Beard trim', 'Colour', 'Bridal trial', 'Perm'])
            ->where('report.services.4', ['id' => LunaFixture::id('services', 'Perm'), 'name' => 'Perm', 'bookings' => 1, 'revenue' => [reportsMoney('EUR', 16000)]])
            ->where('report.staff', fn (Collection $rows): bool => $rows->pluck('name')->all() === ['Luna Hair Studio', 'Elena Papadaki'])
            ->where('report.staff.1', ['id' => LunaFixture::id('staff', 'Elena Papadaki'), 'name' => 'Elena Papadaki', 'bookings' => 1, 'revenue' => [reportsMoney('EUR', 16000)]]));

    reportsBooking('cancelled', 'EUR', 'embed', null, [['Haircut', 'Nikos Georgiou', '2026-11-04 10:00', 3500]]);

    $this->get(tenantUrl('luna', '/admin/reports'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.staff.2', ['id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'name' => 'Nikos Georgiou', 'bookings' => 1, 'revenue' => [reportsMoney('EUR', 0)]]));
});

it('puts Reports on the rail of an owner and a manager after Customers and before Settings, lit on its own page, refuses it to a staff user, and never gates it by the subscription', function () {
    addTenantUser('manager@luna.example', 'manager');
    addTenantUser('staff@luna.example', 'staff');
    reportsAt('2026-11-03 10:00');
    $rail = fn (string $email, array $labels) => $this->actingAs(User::query()->where('email', $email)->sole())->get(tenantUrl('luna', '/admin/reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nav', fn (Collection $nav): bool => $nav->pluck('label')->all() === $labels)
            ->where('nav.7', ['label' => navLabel('reports'), 'url' => '/admin/reports', 'group' => navLabel('groups.business'), 'pending' => null, 'current' => true])
            ->where('nav', fn (Collection $nav): bool => $nav->where('current', true)->count() === 1));

    $rail('owner@luna.example', [navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('shifts'), navLabel('services'), navLabel('staff'), navLabel('customers'), navLabel('reports'), navLabel('team'), navLabel('settings'), navLabel('billing')]);
    $rail('manager@luna.example', [navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('shifts'), navLabel('services'), navLabel('staff'), navLabel('customers'), navLabel('reports'), navLabel('settings')]);

    $this->actingAs(User::query()->where('email', 'staff@luna.example')->sole())->get(tenantUrl('luna', '/admin/reports'))->assertForbidden();

    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);
    $this->actingAs(reportsOwner())->get(tenantUrl('luna', '/admin/reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('report.statuses.3', ['status' => 'confirmed', 'bookings' => 1]));
});

it('reads the figures again by a partial reload of the range and the report alone', function () {
    reportsAt('2026-11-03 10:00', '2026-11-20 10:00');
    $version = (string) app(HandleInertiaRequests::class)->version(request());
    $partial = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'Reports', 'X-Inertia-Partial-Data' => 'range,report'];

    $this->actingAs(reportsOwner())->get(tenantUrl('luna', '/admin/reports?range=month'), $partial)
        ->assertOk()
        ->assertJsonPath('component', 'Reports')
        ->assertJsonPath('props.range', ['word' => 'month', 'from' => '2026-11-01', 'to' => '2026-11-30'])
        ->assertJsonPath('props.report.statuses.3', ['status' => 'confirmed', 'bookings' => 2])
        ->assertJsonMissingPath('props.nav');
});
