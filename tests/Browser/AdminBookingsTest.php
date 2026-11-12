<?php

use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;

/*
 * The Bookings screen and the booking dialog in a real browser: filter to one member, page
 * forward, open a row, reload on the dialog's own address and land on the same dialog, close it
 * and keep the filters, then search for nobody and land on the empty panel. The dialog's footer
 * stays in view above a trail long enough to scroll, the modal's first proof in a screen. The
 * error notice is proven by the component tests alone: a browser cannot be made to fail the read
 * honestly.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * A confirmed Haircut on one of luna's members, starting at the instant given, answering the
 * booking's serial id. Every caller dates it whole days from today: the browser and the
 * application both read the real clock here.
 */
function screenBooking(string $customer, string $staff, DateTimeImmutable $start): int
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', $staff)->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => 3500, 'blocks' => false,
    ]);

    return $id;
}

function nikosId(): string
{
    $id = DB::table('tenant_luna.staff')->where('name', 'Nikos Georgiou')->value('id');
    assert(is_int($id));

    return (string) $id;
}

it('filters to one member, pages forward, opens a row, reloads onto the same dialog, and closes back to the filtered list', function () {
    for ($n = 0; $n < 29; $n++) {
        screenBooking(sprintf('Nikos Customer %02d', $n), 'Nikos Georgiou', athensDaysOn(62 + $n));
    }

    $longTrail = screenBooking('Tessa Trail', 'Nikos Georgiou', athensDaysOn(61, 9));
    screenBooking('Elena Customer', 'Elena Papadaki', athensDaysOn(96, 11));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    DB::table('tenant_luna.audit_log')->insert(array_map(fn (int $n): array => [
        'booking_id' => $longTrail, 'actor' => 'customer', 'action' => 'rescheduled',
        'payload' => json_encode(['moved_from' => $utc(athensDaysOn(31))], JSON_THROW_ON_ERROR),
        'created_at' => $utc(athensDaysOn($n - 30)),
    ], range(0, 29)));
    $uuid = DB::table('tenant_luna.bookings')->where('id', $longTrail)->value('uuid');
    assert(is_string($uuid));
    $nikos = nikosId();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '31']))
        ->assertPathIs('/admin/bookings')
        ->assertSeeIn('.admin-bookings', 'Elena Customer');

    $page->select('#bookings-staff', $nikos)
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '30']))
        ->assertDontSeeIn('.admin-bookings', 'Elena Customer')
        ->assertQueryStringHas('staff', $nikos);

    $page->click('.admin-pagination__next')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '5', 'total' => '30']))
        ->assertSeeIn('.admin-bookings', 'Tessa Trail');

    $page->click('Tessa Trail')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertPathIs('/admin/bookings/'.$uuid)
        ->assertSeeIn('.admin-modal__title', 'Tessa Trail')
        ->assertScript("(() => { const body = document.querySelector('.admin-modal__body'); const close = document.querySelector('.admin-booking-close').getBoundingClientRect(); return body.scrollHeight > body.clientHeight && close.top >= 0 && close.bottom <= window.innerHeight; })()", true);

    $page->refresh()
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertPathIs('/admin/bookings/'.$uuid)
        ->assertSeeIn('.admin-modal__title', 'Tessa Trail')
        ->assertValue('#bookings-staff', $nikos);

    $page->click('.admin-booking-close')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '5', 'total' => '30']))
        ->assertPathIs('/admin/bookings')
        ->assertQueryStringHas('staff', $nikos)
        ->assertValue('#bookings-staff', $nikos)
        ->assertCount('.admin-modal__frame', 0);

    $page->click('.admin-filter-clear')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '25', 'total' => '31']))
        ->assertPathIs('/admin/bookings')
        ->assertQueryStringMissing('staff')
        ->assertValue('#bookings-staff', '')
        ->assertSeeIn('.admin-bookings', 'Elena Customer');

    $page->assertAttribute('#bookings-search', 'placeholder', adminLine('admin/bookings.filters.search_name_email'))
        ->type('#bookings-search', 'nobody-by-this-name')
        ->waitForText(adminLine('admin/bookings.page.empty'))
        ->assertQueryStringHas('search', 'nobody-by-this-name')
        ->assertSeeIn('.admin-bookings-empty', adminLine('admin/bookings.page.empty'))
        ->assertCount('.admin-bookings', 0)
        ->assertCount('.admin-list-error', 0)
        ->assertNoJavaScriptErrors();
});

it('restores the bar from the tab\'s memory on a plain visit, the page number never', function () {
    screenBooking('Elena Customer', 'Elena Papadaki', athensDaysOn(65, 11));
    screenBooking('Nikos Customer', 'Nikos Georgiou', athensDaysOn(66, 11));
    $nikos = nikosId();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2']));
    $page->select('#bookings-staff', $nikos)->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));
    $page->script("sessionStorage.setItem('kr.admin.bookings.filters', JSON.stringify({staff: {$nikos}, status: 42, page: 3}))");

    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertValue('#bookings-staff', $nikos)
        ->assertValue('#bookings-status', '')
        ->assertQueryStringHas('staff', $nikos)
        ->assertSeeIn('.admin-bookings', 'Nikos Customer');
});
