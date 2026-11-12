<?php

use App\Site\DemoSalon;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Support\LunaFixture;

/*
 * Home in a real browser: luna's owner signs in and lands on Home at /admin, sees the setup card
 * and this week's tiles, and presses a day of the next seven, landing on the Calendar's Day of
 * that date from the URL, the query then leaving the address so a reload opens on Day and today.
 * On the system clock, so the booking is dated relative to today: early today, inside this week
 * and the seven days whatever weekday the suite runs on.
 *
 * Then the feeds on the sample salon demo:reset builds: its owner sees the salon's one request
 * under Awaiting approval, opens it, approves it from the dialog, and the row leaves Awaiting
 * approval for the top of Recent activity, spelled as the owner's approval. The salon's owner has
 * no password anybody signs in with, so the walk gives it the demo one.
 *
 * Then the range card: Home opens on this week, This month puts the range in the address and
 * counts the month's bookings in the Bookings tile, and a reload keeps it.
 *
 * Then the bottom half: Recent activity lists five rows of seven, the request's row chipped, All
 * bookings opening the Bookings list; the seven days sum the week with its awaiting share, and
 * the day of the request opens the Calendar's Day on that date.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(function () {
    dropDemoSchemas();
    dropSiteDemo();
});

/**
 * A Haircut on Elena at the Athens start given, written straight to the rows, confirmed or a
 * request awaiting approval on a hold two days long. Answers its id.
 */
function homeWalkBooking(string $customer, DateTimeImmutable $start, string $status = 'confirmed'): int
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $end = $start->add(new DateInterval('PT45M'));
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed', 'uuid' => (string) Str::uuid(), 'status' => $status, 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $status === 'awaiting_approval' ? 'approval' : null,
        'hold_expires_at' => $status === 'awaiting_approval' ? $utc(new DateTimeImmutable('+2 days', new DateTimeZone('UTC'))) : null,
        'payment_mode' => 'onsite', 'requires_approval' => $status === 'awaiting_approval', 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'), 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end), 'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);

    return $id;
}

it('lands the owner on Home with the card and the tiles, and opens the Calendar\'s Day on a day pressed', function () {
    $today = athensDaysOn(0, 0, 30);
    $date = $today->format('Y-m-d');
    homeWalkBooking('Cora Cut', $today);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['hours_confirmed_at' => null]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertVisible(Admin::HOME)
        ->assertSeeIn(Admin::HOME.' h1', adminLine('admin/home.title'))
        ->assertSeeIn('.admin-setup', adminLine('admin/home.setup.title'))
        ->assertAttribute('.admin-setup__item[data-fact="hours"]', 'data-done', 'false')
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1')
        ->assertSeeIn('.admin-figure-tile--cancelled .admin-figure-tile__value', '0')
        ->assertCount('.admin-home-day', 7)
        ->assertAttribute('.admin-home-day:first-child', 'data-date', $date)
        ->assertAttribute('.admin-home-day:first-child', 'data-confirmed', '1')
        ->assertMissing('.admin-home-reports');

    $page->click('.admin-home-day:first-child a')
        ->waitForText($today->format('d/m/Y'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $today->format('d/m/Y'))
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/calendar')
        ->assertUrlIs(Admin::url('luna', Admin::CALENDAR));

    // All staff, so the day shows Elena's booking whatever weekday the suite runs on.
    $page->click('#calendar-who')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Cora Cut', 'service' => 'Haircut']))
        ->assertNoJavaScriptErrors();

    // The link's query left the address once read, so a reload remembers nothing: Day and
    // today.
    $page->refresh()
        ->assertVisible('.admin-calendar')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $today->format('d/m/Y'))
        ->assertPathIs('/admin/calendar');
});

it('approves the sample salon\'s request from Home\'s Awaiting approval, the row moving to the top of Recent activity', function () {
    expect(Artisan::call('demo:reset'))->toBe(0);
    $demo = siteDemoSlug();
    // The sample salon is Greek; its owner chose English here, the language this walk reads.
    DB::table('users')->where('email', DemoSalon::OWNER_EMAIL)->update(['password' => Hash::make(Admin::DEMO_PASSWORD), 'locale' => 'en']);
    $customer = DB::table($demo->schemaName().'.bookings')->where('status', 'awaiting_approval')->sole()->customer_name;
    assert(is_string($customer));

    $page = Admin::signedIn($this, $demo->value, DemoSalon::OWNER_EMAIL)
        ->assertPathIs('/admin')
        ->assertCount('.admin-home-awaiting__row', 1)
        ->assertSeeIn('.admin-home-awaiting__customer', $customer)
        ->assertSeeIn('.admin-home-awaiting__count', adminChoice('admin/shell.kit.counted.awaiting_approval', 1))
        ->assertAttribute('.admin-home-awaiting__all', 'href', '/admin/bookings?status=awaiting_approval');

    $page->click('.admin-home-awaiting__row-open')
        ->assertVisible('.admin-booking-action--approve')
        ->assertSeeIn('.admin-modal__title', $customer)
        ->click('.admin-booking-action--approve')
        ->waitForText(adminLine('admin/home.awaiting.empty'))
        ->assertSeeIn('.admin-home-activity__row:first-child .admin-home-activity__sentence', adminLine('admin/home.activity.approved.user', ['staff' => DemoSalon::OWNER_NAME, 'customer' => $customer]))
        ->assertMissing('.admin-home-awaiting__count')
        ->assertNoJavaScriptErrors();
});

it('opens Home on this week, counts the month on This month with the range in the address, and keeps it on a reload', function () {
    // One booking today, inside this week and this month; one in this month a week or more away,
    // outside this week whatever weekday the suite runs on.
    $today = athensDaysOn(0, 0, 30);
    $day = (int) $today->format('j');
    homeWalkBooking('Cora Cut', $today);
    homeWalkBooking('Mona Month', $day > 7 ? athensDaysOn(1 - $day, 10) : athensDaysOn(7, 10));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertSeeIn('.admin-home-range [aria-pressed="true"]', adminLine('admin/home.range.week'))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '1');

    $page->click('.admin-home-range button:nth-child(4)')
        ->waitForText(adminLine('admin/home.range.line.month', ['from' => $today->format('01/m/Y'), 'to' => $today->format('t/m/Y')]))
        ->assertQueryStringHas('range', 'month')
        ->assertSeeIn('.admin-home-range [aria-pressed="true"]', adminLine('admin/home.range.month'))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '2')
        ->assertNoJavaScriptErrors();

    $page->refresh()
        ->assertVisible(Admin::HOME)
        ->assertQueryStringHas('range', 'month')
        ->assertSeeIn('.admin-home-range [aria-pressed="true"]', adminLine('admin/home.range.month'))
        ->assertSeeIn('.admin-figure-tile--bookings .admin-figure-tile__value', '2');
});

it('lists five activity rows with All bookings, sums the seven days, and opens the Calendar on the day pressed', function () {
    DB::table('tenant_luna.audit_log')->delete();
    $soon = athensDaysOn(2, 11);
    $request = homeWalkBooking('Rhea Request', $soon, 'awaiting_approval');
    homeWalkBooking('Cora Cut', athensDaysOn(0, 23, 30));
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    // Seven rows of the request's trail, the newest a minute ago: Home lists the newest five.
    for ($minute = 7; $minute >= 1; $minute--) {
        DB::table('tenant_luna.audit_log')->insert([
            'booking_id' => $request, 'actor' => 'customer', 'action' => 'held',
            'payload' => json_encode(['status_to' => 'awaiting_approval'], JSON_THROW_ON_ERROR),
            'created_at' => $now->sub(new DateInterval(sprintf('PT%dM', $minute)))->format('Y-m-d H:i:sP'),
        ]);
    }

    $date = $soon->format('Y-m-d');
    $day = '.admin-home-day[data-date="'.$date.'"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertCount('.admin-home-activity__row', 5)
        ->assertSeeIn('.admin-home-activity__row:first-child .admin-home-activity__sentence', adminLine('admin/home.activity.held.request', ['customer' => 'Rhea Request']))
        ->assertVisible('.admin-home-activity__row:first-child .admin-chip--attention')
        ->assertAttribute('.admin-home-activity__all', 'href', '/admin/bookings')
        ->assertSeeIn('.admin-home-days__summary', adminChoice('admin/home.days.summary.one_awaiting', 2, ['awaiting' => '1']))
        ->assertAttribute($day, 'data-awaiting', '1')
        ->assertAttribute($day, 'data-confirmed', '0');

    $page->click($day.' a')
        ->waitForText($soon->format('d/m/Y'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $soon->format('d/m/Y'))
        ->assertPathIs('/admin/calendar')
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna'))
        ->click('.admin-home-activity__all')
        ->assertPathIs('/admin/bookings')
        ->assertNoJavaScriptErrors();
});
