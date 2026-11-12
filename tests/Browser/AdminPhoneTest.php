<?php

use App\Http\Controllers\Admin\BookingActionController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * The admin at 390 pixels, on the plugin's iPhone 15: the owner who approves a request from the
 * chair. The rail is behind a control in the top bar and opens as a drawer; the Calendar opens on
 * Day, and Week, pressed, says it needs a wider screen and offers Day back, where a block opens the
 * booking dialog filling the screen; Bookings lists Customer, When, Status and Total and opens the
 * dialog from a row, its footer pinned under a body that scrolls; the dialog's Cancel goes through
 * its confirm to the toast, inside the screen and clear of the dialog's actions. Each walk opens
 * the rail from the control and reaches another screen through it. A walk reads the Menu control
 * carrying the pending count the rail's Bookings entry carries, so a request waiting is seen with
 * the drawer closed, and the others none where none is pending. A last walk reads the wizard,
 * Month, Services, Staff and Settings at the same width, none of them scrolling the page sideways,
 * Month's day a cell with no control inside it. The rest of the suite stays on the plugin's desktop
 * device.
 *
 * The bookings are written straight to luna's rows, dated whole days from the tenant's today in
 * Athens on the real clock, which the browser and the application both read.
 */

/** Week's notice below the admin's breakpoint, a key of the calendar section read through the translator. */
const PHONE_WEEK_NOTICE = 'admin/calendar.grid.week_needs_width';

/** The Calendar's footnote under its grid card, there once the screen has drawn in any view. */
const PHONE_FOOTNOTE = 'admin/calendar.page.footnote';

const PHONE_DRAWER = '.admin-modal__frame--drawer';

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * A Haircut on Elena starting at the instant given, confirmed, or a request on an open approval
 * window, answering the booking's uuid and serial id.
 *
 * @return array{uuid: string, id: int}
 */
function phoneBooking(string $customer, DateTimeImmutable $start, bool $request = false): array
{
    $uuid = (string) Str::uuid();
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $end = $start->add(new DateInterval('PT60M'));
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => $request ? 'awaiting_approval' : 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'customer_phone' => '+30 210 5550101', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $request ? 'approval' : null,
        'hold_expires_at' => $request ? DB::raw("now() + interval '2 days'") : null,
        'payment_mode' => 'onsite', 'requires_approval' => $request, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'),
        'staff_id' => LunaFixture::id('staff', 'Elena Papadaki'),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);

    return ['uuid' => $uuid, 'id' => $id];
}

it('opens a booking from a block of the Day calendar on a phone, Week saying it needs a wider screen, and reaches Bookings through the rail\'s drawer', function () {
    $booking = phoneBooking('Cora Cut', athensDaysOn(0, 10));
    $block = '.fc-event[data-block-id="'.$booking['uuid'].':0"]';

    // The Calendar opens on Day, so the first paint is the grid and never Week's notice.
    $page = Admin::onPhoneCalendar($this, 'luna', Admin::owner('luna'))->waitForText(adminLine(PHONE_FOOTNOTE));
    $page->assertPathIs('/admin/calendar')
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertNotPresent('.admin-menu .admin-menu-pending')
        ->assertNotPresent('.admin-aside')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertNotPresent('.admin-calendar-narrow')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // Week, the second view, says it needs a wider screen in place of the grid.
    $page->click('.admin-calendar-view button:nth-child(2)')
        ->waitForText(adminLine(PHONE_WEEK_NOTICE))
        ->assertNotPresent('.fc')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.week'))
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // All staff, so today's Day shows Elena's booking whatever weekday the suite runs on: on a
    // weekend she holds no hours and Scheduled staff would leave her out.
    $page->click('#calendar-who')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.all'))
        ->click('.admin-calendar-narrow__day')
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Cora Cut', 'service' => 'Haircut']))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertNotPresent('.admin-calendar-narrow')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // Day is a column per member: Elena's and Nikos's, wider together than the phone, so their row
    // scrolls sideways inside the calendar card while the page does not.
    $page->assertCount('.admin-calendar-column', 2)
        ->assertScript(PhoneScripts::DAY_COLUMNS_SCROLL, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->click($block)
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertPathIs('/admin/calendar')
        ->assertSeeIn('.admin-modal__title', 'Cora Cut')
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->click('.admin-booking-close')
        ->assertNotPresent('.admin-booking-dialog');

    $page->click('.admin-menu')
        ->assertScript(PhoneScripts::DRAWER_AT_EDGE, true)
        ->assertCount(PHONE_DRAWER.' .admin-rail-entry', 11)
        ->assertAttribute(PHONE_DRAWER.' [aria-current="page"]', 'href', '/admin/calendar')
        ->click(PHONE_DRAWER.' a[href="/admin/bookings"]')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertPathIs('/admin/bookings')
        ->assertNotPresent(PHONE_DRAWER)
        ->assertScript(PhoneScripts::FOCUS_ON_MENU, true)
        ->assertNoJavaScriptErrors();
});

it('opens the dialog from a row of Bookings on a phone, the table without Service and Staff, the dialog filling the screen with its footer pinned under a scrolling body, and reaches the Calendar through the rail', function () {
    $booking = phoneBooking('Tessa Trail', athensDaysOn(61, 9));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    DB::table('tenant_luna.audit_log')->insert(array_map(fn (int $n): array => [
        'booking_id' => $booking['id'], 'actor' => 'customer', 'action' => 'rescheduled',
        'payload' => json_encode(['moved_from' => $utc(athensDaysOn(31))], JSON_THROW_ON_ERROR),
        'created_at' => $utc(athensDaysOn($n - 30)),
    ], range(0, 29)));

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->click('.admin-menu')
        ->click(PHONE_DRAWER.' a[href="/admin/bookings"]')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertPathIs('/admin/bookings')
        ->assertVisible('.admin-bookings__when')
        ->assertVisible('.admin-bookings__total')
        ->assertMissing('.admin-bookings__services')
        ->assertMissing('.admin-bookings__staff')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->click('Tessa Trail')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertPathIs('/admin/bookings/'.$booking['uuid'])
        ->assertSeeIn('.admin-modal__title', 'Tessa Trail')
        ->assertSeeIn('.admin-booking-items', 'Haircut')
        ->assertSeeIn('.admin-booking-items', 'Elena Papadaki')
        ->assertScript(PhoneScripts::ITEMS_CELLS_APART, true)
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->assertScript(PhoneScripts::DIALOG_BODY_SCROLLS, true)
        ->click('.admin-booking-close')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertPathIs('/admin/bookings');

    $page->click('.admin-menu')
        ->assertAttribute(PHONE_DRAWER.' [aria-current="page"]', 'href', '/admin/bookings')
        ->click(PHONE_DRAWER.' a[href="/admin/calendar"]')
        ->waitForText(adminLine(PHONE_FOOTNOTE))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertPathIs('/admin/calendar')
        ->assertNotPresent(PHONE_DRAWER)
        ->assertNoJavaScriptErrors();
});

it('cancels a booking from the dialog on a phone through its confirm to the toast, inside the screen and clear of the dialog\'s actions, and reaches Services through the rail', function () {
    phoneBooking('Cora Cancelled', athensDaysOn(63));

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->click('.admin-menu')
        ->click(PHONE_DRAWER.' a[href="/admin/bookings"]')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('Cora Cancelled')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning'))
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertScript(PhoneScripts::TOASTS_INSIDE, true);

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('.admin-menu')
        ->click(PHONE_DRAWER.' a[href="/admin/services"]')
        ->waitForText('Beard trim')
        ->assertPathIs('/admin/services')
        ->assertNotPresent(PHONE_DRAWER)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('carries the pending count on the Menu control with the drawer closed, the count the rail\'s Bookings entry carries inside it', function () {
    phoneBooking('Rita Request', athensDaysOn(2, 14), request: true);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->assertNotPresent(PHONE_DRAWER)
        ->assertSeeIn('.admin-menu .admin-menu-pending', '1')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->click('.admin-menu')
        ->assertSeeIn(PHONE_DRAWER.' a[href="/admin/bookings"] .admin-rail-pending', '1')
        ->click(PHONE_DRAWER.' a[href="/admin/bookings"]')
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']))
        ->assertNotPresent(PHONE_DRAWER)
        ->assertSeeIn('.admin-menu .admin-menu-pending', '1')
        ->assertNoJavaScriptErrors();
});

it('fits the wizard, Month, Services, Staff and Settings to a phone, none of them scrolling the page sideways', function () {
    $page = Admin::onPhoneCalendar($this, 'luna', Admin::owner('luna'))->waitForText(adminLine(PHONE_FOOTNOTE));

    $page->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens']))
        ->assertScript(PhoneScripts::WIZARD_DAY_OVER_TIMES, true)
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->click('.admin-wizard-cancel')
        ->assertNotPresent('.admin-wizard');

    $page->click('.admin-calendar-view button:nth-child(3)')
        ->waitForText((new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('F Y'))
        ->assertPresent('td.admin-calendar-day[tabindex="0"]')
        ->assertNotPresent('.admin-calendar-day button')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    foreach (['/services' => 'Beard trim', '/staff' => 'Nikos Georgiou', '/settings' => adminLine('admin/settings.sections.booking_rules')] as $path => $text) {
        $page->navigate(Admin::url('luna', $path))
            ->waitForText($text)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    }

    $page->assertScript(PhoneScripts::SAVE_BAR_INSIDE, true);

    $page->navigate(Admin::url('luna', '/services'))->waitForText('Beard trim')->click('Beard trim')
        ->waitForText(adminLine('admin/services.form.payment_mode'))
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(PhoneScripts::SAVE_BAR_INSIDE, true);

    $page->navigate(Admin::url('luna', '/staff'))->waitForText('Nikos Georgiou')->click('Nikos Georgiou')
        ->waitForText(adminLine('admin/staff.form.weekly'))
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(PhoneScripts::SAVE_BAR_INSIDE, true)
        ->assertNoJavaScriptErrors();
});
