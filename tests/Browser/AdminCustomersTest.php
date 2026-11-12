<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\NewBookingController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;

/*
 * The Customers screen in a real browser: the owner opens Customers from the rail, searches by
 * part of an address, opens the customer, opens one of their bookings in the dialog over the
 * customer's page and cancels it, the answer arriving through the server's redirect and flash with
 * the page's address unchanged, and a reload opening no dialog. The settledness gate's waiting
 * states are proven by the component tests alone: a browser cannot be made to hold a read
 * honestly. Then the erasure from a customer's page: the Danger zone's control, its dialog
 * refusing while a booking is live, the booking cancelled, the confirm naming the count, and the
 * list afterwards without the address. Then the page's tiles and Book now: the owner reads the
 * five tiles, presses Book now, finds the customer filled in on the wizard's last step, creates
 * the booking and sees Upcoming grow. Then the Profile card: the owner saves tags, a birthday and
 * a language, the head shows the tags, and the list's name cell shows them as chips. Last the
 * list's Download CSV: pressed under a search, the request carries the search.
 *
 * The bookings are written straight to luna's rows, every start whole days from today, because
 * the browser and the application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

// The rail's own entry, reached by its target: the screen's heading reads "Customers" too.
const CUSTOMERS_WALK_ENTRY = '.admin-rail a[href="/admin/customers"]';

/**
 * A confirmed Haircut on Elena's row under an address, starting at the instant given, answering
 * the booking's uuid.
 */
function customersWalkBooking(string $name, string $email, DateTimeImmutable $start): string
{
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $uuid = (string) Str::uuid();
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => 'confirmed', 'customer_name' => $name, 'customer_email' => $email,
        'customer_phone' => '+30 210 7654321', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => 3500, 'blocks' => false,
    ]);

    return $uuid;
}

it('opens Customers from the rail, searches, opens a customer, opens a booking over the customer\'s page and cancels it', function () {
    customersWalkBooking('Casey C.', 'Casey@Example.com', athensDaysOn(61));
    $latest = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(63));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(62));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2']))
        ->assertPathIs('/admin/customers')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/customers')
        ->assertSeeIn('.admin-customers', 'Ada North')
        ->assertSeeIn('.admin-customers', 'Casey Customer')
        ->assertSeeIn('.admin-customers', adminLine('admin/customers.table.also_booked_as', ['names' => 'Casey C.']));

    $page->assertAttribute('#customers-search', 'placeholder', adminLine('admin/customers.page.hint_contact'))
        ->type('#customers-search', 'CASEY@')
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '1', 'total' => '1']))
        ->assertQueryStringHas('search', 'CASEY@')
        ->assertDontSeeIn('.admin-customers', 'Ada North')
        ->assertSeeIn('.admin-customers__email', 'casey@example.com');

    $page->click('.admin-customers .admin-customers__name')
        ->waitForText('casey@example.com')
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertSeeIn('.admin-customer-head h1', 'Casey Customer')
        ->assertSeeIn('.admin-customer-head__email', 'casey@example.com')
        ->assertSeeIn('.admin-bookings__count', adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2']))
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/customers');

    $page->click('.admin-bookings__row:first-child .admin-row-select')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-modal__title', 'Casey Customer')
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertQueryStringMissing('booking')
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertQueryStringMissing('booking');

    $page->click('.admin-booking-close')
        ->assertCount('.admin-modal__frame', 0)
        ->assertSeeIn('.admin-bookings__row .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'));

    // The dialog was the page's own state: a reload lands on the customer's page with none open.
    $page->refresh()
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '2', 'total' => '2']))
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertCount('.admin-modal__frame', 0)
        ->assertSeeIn('.admin-bookings__row .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->where('uuid', $latest)->value('status'))->toBe('cancelled');
});

it('erases a customer from their page: refused while a booking is live, the booking cancelled, the confirm naming the count, and the list without the address', function () {
    $past = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(-3));
    $live = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(63));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(62));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2']))
        ->click('.admin-customers__row:first-child .admin-customers__name')
        ->waitForText(adminLine('admin/customers.erasure.title'))
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertSeeIn('.admin-customer-danger__what', adminLine('admin/customers.erasure.what'));

    // A booking still to come: the dialog names it and says to cancel first, and offers no erasure.
    $page->click('.admin-customer-erase')
        ->waitForText(adminLine('admin/customers.erasure.refused_title'))
        ->assertSeeIn('.admin-erase-refused__sentence', adminChoice(CustomerController::LIVE, 1))
        ->assertCount('.admin-erase-prompt__confirm', 0)
        ->click('.admin-erase-refused__close')
        ->assertCount('.admin-modal__frame', 0);

    $page->click('.admin-bookings__row:first-child .admin-row-select')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->click('.admin-booking-close')
        ->assertCount('.admin-modal__frame', 0);

    $page->click('.admin-customer-erase')
        ->waitForText(adminLine('admin/customers.erasure.confirm_title'))
        ->assertSeeIn('.admin-erase-prompt__count', adminChoice('admin/customers.erasure.count', 2))
        ->click('.admin-erase-prompt__confirm')
        ->waitForText(adminLine(CustomerController::ERASED))
        ->assertPathIs('/admin/customers')
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '1', 'total' => '1']))
        ->assertSeeIn('.admin-customers', 'Ada North')
        ->assertDontSeeIn('.admin-customers', 'Casey Customer')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->whereIn('uuid', [$past, $live])->pluck('customer_email')->all())->toBe([null, null])
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $live)->value('status'))->toBe('cancelled');
});

it('reads the customer\'s tiles, books them from Book now with their fields filled in on the wizard\'s last step, and sees Upcoming grow', function () {
    $past = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(-3));
    DB::table('tenant_luna.bookings')->where('uuid', $past)->update(['status' => 'completed']);
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(62));
    $cancelled = customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(-2));
    DB::table('tenant_luna.bookings')->where('uuid', $cancelled)->update(['status' => 'cancelled']);

    // The Tuesday of the second availability window, Elena's 09:00 offered whatever the date.
    $today = athensToday();
    $day = firstWindowDay(2);
    $months = ((int) $day->format('Y') - (int) $today->format('Y')) * 12 + (int) $day->format('n') - (int) $today->format('n');
    $nine = $day->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    $tile = fn (string $id): string => '.admin-customer-glance__figure--'.$id.' strong';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.tiles.title'))
        ->assertSeeIn($tile('upcoming'), '1')
        ->assertSeeIn($tile('completed'), '1')
        ->assertSeeIn($tile('no-shows'), '0')
        ->assertSeeIn($tile('cancelled'), '1')
        ->assertSeeIn($tile('last-visit'), athensDaysOn(-3)->format('d/m/Y'));

    $page->click('.admin-customer-book')
        ->waitForText(adminLine('admin/customers.wizard.steps.when'))
        ->assertPresent('.admin-wizard')
        ->assertSeeIn('.admin-wizard-strip li:nth-child(3)', 'Casey Customer')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens']));

    for ($month = 0; $month < $months; $month++) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click('button[data-date="'.$day->format('Y-m-d').'"]')
        ->waitForText(adminLine('admin/customers.wizard.periods.morning'))
        ->click('.admin-wizard-slot[value="'.$nine.'"]')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.fields.name'))
        ->assertValue('#wizard-customer-name', 'Casey Customer')
        ->assertValue('#wizard-customer-email', 'casey@example.com')
        ->assertValue('#wizard-customer-phone', '+30 210 7654321')
        ->click('.admin-wizard-create')
        ->waitForText(adminLine(NewBookingController::CREATED))
        ->assertCount('.admin-wizard', 0)
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertSeeIn($tile('upcoming'), '2')
        ->assertSeeIn('.admin-bookings__count', adminLine('admin/bookings.table.count', ['count' => '4', 'total' => '4']))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->where('customer_email', 'casey@example.com')->where('status', 'confirmed')->count())->toBe(2);
});

it('saves the customer\'s profile from the Profile card, the head showing the tags, and the list\'s name cell the same chips', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(5));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.profile.title'))
        ->assertCount('.admin-customer-head__chips', 0)
        ->type('#customer-tags', 'VIP, colour, vip')
        ->select('#customer-language', 'el')
        ->click('.admin-customer-profile__save')
        ->waitForText(adminLine('admin/customers.flashes.profile_saved'))
        ->assertPathIs('/admin/customers/casey%40example.com')
        ->assertValue('#customer-tags', 'VIP, colour')
        ->assertValue('#customer-language', 'el')
        ->assertSeeIn('.admin-customer-head__chips', 'VIP')
        ->assertSeeIn('.admin-customer-head__chips', 'colour')
        ->assertNoJavaScriptErrors();

    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText('casey@example.com')
        ->assertSeeIn('.admin-customers__row:first-child .admin-customers__chips', 'VIP')
        ->assertSeeIn('.admin-customers__row:first-child .admin-customers__chips', 'colour')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.customer_profiles')->where('email', 'casey@example.com')->value('tags'))->toBe('{VIP,colour}');
});

it('adds a note from the Notes card and sees it at the top of the list with the owner\'s name, then removes it through the confirm', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(5));
    $owner = DB::table('users')->where('email', Admin::owner('luna'))->value('name');
    assert(is_string($owner));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.notes.title'))
        ->assertCount('.admin-customer-note', 0)
        ->type('#customer-note', 'Prefers mornings.')
        ->click('.admin-customer-notes__add')
        ->waitForText(adminLine('admin/customers.flashes.note_added'))
        ->assertValue('#customer-note', '')
        ->assertSeeIn('.admin-customer-note:first-child .admin-customer-note__body', 'Prefers mornings.')
        ->assertSeeIn('.admin-customer-note:first-child .admin-customer-note__byline', $owner)
        ->click('.admin-customer-note:first-child .admin-customer-note__remove')
        ->click('.admin-note-remove__confirm')
        ->waitForText(adminLine('admin/customers.flashes.note_removed'))
        ->assertCount('.admin-customer-note', 0)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.customer_notes')->count())->toBe(0);
});

it('blocks the address from the Online booking card through the confirm, the list\'s name cell then carrying Blocked, and unblocks it', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(5));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.block.open'))
        ->type('#customer-block-reason', 'Three no-shows')
        ->click('.admin-customer-block__block')
        ->click('.admin-block-prompt__confirm')
        ->waitForText(adminLine('admin/customers.flashes.blocked'))
        ->assertSeeIn('.admin-customer-block__reason', 'Three no-shows')
        ->assertNoJavaScriptErrors();

    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText('casey@example.com')
        ->assertSeeIn('.admin-customers__row:first-child .admin-customers__blocked', adminLine('admin/customers.table.blocked'))
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna', '/customers/casey%40example.com'))
        ->waitForText(adminLine('admin/customers.block.unblock'))
        ->click('.admin-customer-block__unblock')
        ->waitForText(adminLine('admin/customers.flashes.unblocked'))
        ->assertSee(adminLine('admin/customers.block.open'))
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.customer_profiles')->where('email', 'casey@example.com')->value('blocked_at'))->toBeNull();
});

it('downloads the list as CSV under the search on screen: the request carries the search and not the page', function () {
    customersWalkBooking('Casey Customer', 'casey@example.com', athensDaysOn(61));
    customersWalkBooking('Ada North', 'ada@example.test', athensDaysOn(62));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(CUSTOMERS_WALK_ENTRY)
        ->waitForText(adminLine('admin/customers.table.count', ['count' => '2', 'total' => '2']))
        ->type('#customers-search', 'CASEY@')
        // The search's read has landed once the address carries it and the list's own count reads
        // one of one; a bare wait on the count's words would hold at once on the pager's "Page 1 of
        // 1" while the list still shows both, the button still holding the empty search.
        ->assertScript(sprintf(<<<'JS'
            () => new URL(window.location.href).searchParams.get('search') === 'CASEY@'
                && document.querySelector('.admin-customers__count')?.textContent === %s
            JS, json_encode(adminLine('admin/customers.table.count', ['count' => '1', 'total' => '1']))), true)
        ->assertSeeIn('.admin-customers-bar .admin-customers-download', adminLine('admin/customers.csv.download'));

    // The page's fetch is watched, not stood in for: the file is still asked of the server.
    $page->script(<<<'JS'
        (() => {
            window.__customersCsv = [];
            const send = window.fetch.bind(window);
            window.fetch = (url, init) => { window.__customersCsv.push(String(url)); return send(url, init); };
        })()
        JS);
    $page->click('.admin-customers-download')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect($page->script('window.__customersCsv'))->toBe(['/admin/customers/csv?search=CASEY%40'])
        ->and($page->script("document.querySelector('.admin-customers-download').getAttribute('aria-busy')"))->toBeNull();
});
