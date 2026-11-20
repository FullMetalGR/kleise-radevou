<?php

use App\Http\Controllers\Admin\BookingActionController;
use App\Platform\PasswordLinks;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * The admin at 390 pixels, on the plugin's iPhone 15: the owner who approves a request from the
 * chair. The rail is behind a control in the top bar and opens as a drawer; the Calendar opens on
 * Day, and Week, pressed, says it needs a wider screen and offers Day back, where a block opens the
 * booking dialog filling the screen; Bookings lists Customer, When, Status and Total and opens the
 * dialog from a row, its footer pinned under a body that scrolls; the dialog's Cancel goes through
 * its confirm to the toast, inside the screen and clear of the dialog's actions. Each walk opens
 * the rail from the control and reaches another screen through it. The dialog of a confirmed
 * booking is opened once more by an owner who reads Greek, where its three actions are wider than
 * its footer, and each stands inside the dialog. The confirm over the dialog rises from the bottom
 * edge as a sheet; one more walk opens a request's Reject at a tablet's width, types a reason, and
 * narrows the window to the phone's and widens it back, the confirm a card, then a sheet, then a
 * card again, the reason and the focus kept throughout. A walk reads the Menu control
 * carrying the pending count the rail's Bookings entry carries, so a request waiting is seen with
 * the drawer closed, and the others none where none is pending. A last walk reads the wizard,
 * Month, Services, Staff and Settings at the same width, none of them scrolling the page sideways,
 * Month's day its number and how many bookings it has with no chip, the whole cell its date menu,
 * whose Open day opens Day view on it, Settings' save bar beside the help bubble and resting after
 * the form's last control at its end, and one more opens every screen of the admin, as an owner
 * who reads English and as one who reads Greek, and reads that none is wider than the phone, the
 * Team screen's members read as rows, each value beside its column name; an email of sixty
 * characters with no break wraps inside its row there. A walk in Greek holds a field's control to
 * its place: the selects of Bookings' filters, listing a member and a service whose names run to
 * sixty characters, stand inside their fields with the page not scrolling sideways. Another reads
 * Settings' save bar in Greek while it says why Save is unavailable, the sentence wrapped inside
 * its column with the address in it broken, then widens the window to where the bar is as wide as
 * what it holds: at both widths the bar ends before the help bubble. The rest of the suite stays
 * on the plugin's desktop device.
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
        ->assertScript(PhoneScripts::DIALOG_BODY_SCROLLS, true);
    Look::shot($page, 'booking-dialog-390');

    $page->click('.admin-booking-close')
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
        ->assertScript(PhoneScripts::SHEET_AT_BOTTOM, true)
        ->click('.admin-cancel-prompt__confirm')
        ->waitForText(adminLine(BookingActionController::CANCELLED))
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.cancelled'))
        ->assertCount('.admin-booking-actions', 0)
        ->assertScript(PhoneScripts::TOASTS_INSIDE, true);
    Look::shot($page, 'toast-over-filling-dialog-390');

    $page->click('.admin-booking-close')->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('.admin-menu')
        ->click(PHONE_DRAWER.' a[href="/admin/services"]')
        ->waitForText('Beard trim')
        ->assertPathIs('/admin/services')
        ->assertNotPresent(PHONE_DRAWER)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('keeps the actions of a confirmed booking\'s dialog inside the dialog on a phone in Greek, where Cancel, Move and Close are wider than its footer, and those of its cancel confirm inside the sheet', function () {
    phoneBooking('Greta Greek', athensDaysOn(64));
    // The labels are longer in Greek, and a footer sets its actions against its end edge, so a
    // row of them too wide runs out of the start edge, where the page never scrolls. The language
    // is the owner's before the sign-in, as on the walk of every screen.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1'], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390);

    $page->click('Greta Greek')
        ->waitForText(adminLine('admin/bookings.dialog.items.title', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-booking-action--cancel', adminLine('admin/bookings.actions.cancel', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-booking-move', adminLine('admin/bookings.move.open', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-booking-close', adminLine('admin/bookings.dialog.close', [], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::DIALOG_FILLS_SCREEN, true)
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // The cancel confirm over it is a sheet at the bottom edge, its two Greek actions inside it
    // too; it is closed by its own way out, so nothing is cancelled.
    $page->click('.admin-booking-action--cancel')
        ->waitForText(adminLine('admin/bookings.cancel.warning', [], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::SHEET_AT_BOTTOM, true)
        ->assertScript(PhoneScripts::DIALOG_FOOTER_BUTTONS_INSIDE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->click('.admin-cancel-prompt__keep')
        ->assertMissing('.admin-cancel-prompt')
        ->assertVisible('.admin-booking-action--cancel')
        ->assertNoJavaScriptErrors();
});

it('keeps a confirm\'s typed reason and its focus as the window narrows to a phone and widens back, the confirm a card in the middle, then a sheet at the bottom edge, then a card again at every width above the breakpoint', function () {
    phoneBooking('Rhea Request', athensDaysOn(65), request: true);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1024, 768);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->waitForText(adminLine('admin/bookings.table.count', ['count' => '1', 'total' => '1']));

    $page->click('Rhea Request')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-action--reject')
        ->waitForText(adminLine('admin/bookings.reject.reason'))
        ->type('#reject-reason', 'Fully booked that week')
        ->assertScript(Admin::focusIsOn('#reject-reason'), true)
        ->assertScript(PhoneScripts::CARD_IN_MIDDLE, true);
    Look::shot($page, 'confirm-over-dialog-1024');

    $page->resize(390, 844)
        ->assertScript(PhoneScripts::SHEET_AT_BOTTOM, true)
        ->assertValue('#reject-reason', 'Fully booked that week')
        ->assertScript(Admin::focusIsOn('#reject-reason'), true);
    Look::shot($page, 'confirm-over-dialog-390');

    $page->resize(1024, 768)
        ->assertScript(PhoneScripts::CARD_IN_MIDDLE, true)
        ->assertValue('#reject-reason', 'Fully booked that week')
        ->assertScript(Admin::focusIsOn('#reject-reason'), true);

    // A card just above the breakpoint, and on a wide desktop.
    $page->resize(800, 900)->assertScript(PhoneScripts::CARD_IN_MIDDLE, true);
    Look::shot($page, 'confirm-over-dialog-800');

    $page->resize(1440, 900)
        ->assertScript(PhoneScripts::CARD_IN_MIDDLE, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'confirm-over-dialog-1440');
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

it('fits the wizard, Month, Services, Staff and Settings to a phone, none of them scrolling the page sideways, and opens a day from Month\'s date menu', function () {
    phoneBooking('Cora Cut', athensDaysOn(0, 10));
    phoneBooking('Bo Brush', athensDaysOn(0, 12));
    $today = '.admin-calendar-month__day[data-date="'.athensToday()->format('Y-m-d').'"]';
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

    // A day holds its number and its count, the count's words read with the day's name alone.
    $page->click('.admin-calendar-view button:nth-child(3)')
        ->waitForText((new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('F Y'))
        ->assertSeeIn($today.' .admin-calendar-month__count', '2')
        ->assertScript(sprintf("document.querySelector('%s .admin-calendar-date').textContent.includes(%s)", $today, json_encode(adminChoice('admin/calendar.cells.count', 2))), true)
        ->assertCount($today.' button', 1)
        ->assertNotPresent('.admin-calendar-chip')
        ->assertNotPresent('.admin-calendar-more')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->click($today.' .admin-calendar-date')
        ->assertSeeIn('.admin-calendar-date__day', adminLine('admin/calendar.cells.open_day'))
        ->click('.admin-calendar-date__day')
        ->waitForText('Cora Cut')
        ->assertNotPresent('.admin-calendar-month')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'));

    foreach (['/services' => 'Beard trim', '/staff' => 'Nikos Georgiou', '/settings' => adminLine('admin/settings.sections.booking_rules')] as $path => $text) {
        $page->navigate(Admin::url('luna', $path))
            ->waitForText($text)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    }

    // Settings' save bar floats beside the help bubble and, at the end of the form, rests after its last control.
    $page->assertScript(PhoneScripts::SAVE_BAR_INSIDE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_BESIDE_BUBBLE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_CLEARS_LAST_CONTROL, true);

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

it('keeps Settings\' save bar clear of the help bubble in Greek while it says why Save is unavailable, the sentence wrapped inside its column on a phone with the address in it broken, and at a width where the bar is as wide as what it holds', function () {
    // The sentence is one line in English on a desktop. In Greek it runs to several on a phone,
    // beside a wider button, and to more than a row holds at 1000 pixels, so at both widths the
    // bar reaches as far as it is let, toward the corner the bubble stands over.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $reason = adminLine('admin/settings.validation.public_email', [], SiteLanguage::Greek);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/settings'))
        ->waitForText(adminLine('admin/settings.sections.booking_rules', [], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->type('#settings-public-email', 'x')
        ->assertSeeIn('.admin-save-bar__reason', $reason)
        ->assertScript(PhoneScripts::SAVE_BAR_REASON_INSIDE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_INSIDE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_BESIDE_BUBBLE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // The field is typed in again, so the walk does not lean on the form keeping its state
    // across the two layouts.
    $page->resize(1000, 800)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 1000)
        ->type('#settings-public-email', 'x')
        ->assertSeeIn('.admin-save-bar__reason', $reason)
        ->assertScript(PhoneScripts::SAVE_BAR_REASON_INSIDE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_INSIDE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_BESIDE_BUBBLE, true)
        ->assertNoJavaScriptErrors();
});

it('keeps each select of Bookings\' filters inside its field on a phone in Greek, the page not scrolling sideways, where a member and a service carry names of sixty characters', function () {
    // A select is as wide as its longest option unless its frame holds it to the field, and the
    // filters list every member and every service by name.
    $member = 'Elena Konstantina Papadaki-Triantafyllopoulou of Luna Studio';
    $service = 'Haircut with a wash, long layers, a blow-dry and a hand wave';
    expect([strlen($member), strlen($service)])->toBe([60, 60]);
    DB::table('tenant_luna.staff')->where('id', LunaFixture::id('staff', 'Elena Papadaki'))->update(['name' => $member]);
    DB::table('tenant_luna.services')->where('id', LunaFixture::id('services', 'Haircut'))->update(['name' => $service]);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/bookings'))
        ->assertVisible('#bookings-service')
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, SiteLanguage::Greek->value)
        ->assertScript(PhoneScripts::VIEWPORT_WIDTH, 390)
        ->assertScript(PhoneScripts::SELECTS_WITH_LONG_OPTION, 2)
        ->assertScript(PhoneScripts::SELECTS_INSIDE_FIELDS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});

it('keeps every screen of the admin no wider than a phone, a customer\'s page among them, the Team screen\'s members read as rows with the help bubble on screen', function (SiteLanguage $language) {
    phoneBooking('Cora Cut', athensDaysOn(0, 10));
    // A line longer in one language, on a control that does not wrap, widens a screen in that
    // language alone, so the owner reads every screen in each. The language is the owner's before
    // the sign-in: the server the walk drives keeps the signed-in user between requests.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    foreach (Look::SCREENS as ['path' => $path, 'hook' => $drawn]) {
        $page->navigate(Admin::url('luna', $path))
            ->assertVisible($drawn)
            ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    }

    $page->navigate(Admin::url('luna', '/customers'))
        ->click('.admin-customers__row:first-child .admin-customers__name')
        ->assertVisible('.admin-customer-screen')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    // The members table has no room for its columns on a phone: each member is a block of
    // labelled lines, nothing scrolls sideways, and the bubble fixed to the screen's corner stays
    // on it.
    $page->navigate(Admin::url('luna', '/team'))
        ->assertVisible('.admin-team-member')
        ->assertScript(PhoneScripts::TEAM_TABLE_AS_ROWS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(PhoneScripts::BUBBLE_INSIDE, true)
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('wraps an email of sixty characters with no break inside its row on the Team screen, neither the page nor the table scrolling sideways', function () {
    $email = str_repeat('a', 47).'@luna.example';
    expect(strlen($email))->toBe(60);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);

    $page->navigate(Admin::url('luna', '/team'))
        ->assertVisible('.admin-team-member')
        ->type('#team-invite-name', 'Maria Ioannou')
        ->type('#team-invite-email', $email)
        ->click('.admin-team-invite__send')
        ->waitForText(adminChoice('admin/team.flashes.invited', PasswordLinks::hours(), ['email' => $email]))
        ->assertScript(PhoneScripts::TEAM_TABLE_AS_ROWS, true)
        ->assertScript(PhoneScripts::LONG_EMAIL_WRAPS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
});
