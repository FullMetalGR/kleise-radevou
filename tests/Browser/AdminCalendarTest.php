<?php

use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\SettingsController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Support\LunaFixture;

/*
 * The Calendar in a real browser: an owner signs in and opens the week of a day Elena works, its
 * bookings as blocks, picks one member under Who and sees the other's block go, opens the wizard
 * from an empty slot prefilled with that day and the member pinned, by a click and by a drag alike,
 * moves to next week, opens a block there into the dialog and approves the request from it, the
 * block staying on next week's grid and losing its badge as the window wanted and the booking open
 * are re-read together, since the action's redirect lands the default week; a staff user signs in,
 * lands on the Calendar pinned to Me with their own bookings alone, Who read-only, opens their own
 * request to Approve and Reject with neither email nor phone, and finds no slot selection. Month:
 * the owner opens the month on bookings a week apart, reads each day's counts by status off its
 * chips with no legend, and presses a day into Day view, which opens no wizard: Month offers no
 * slot selection. A booking awaiting payment wears its own badge on the week and the day grids, the
 * legend naming both badges, while Month keeps counting it as its chip; and the day's press is its
 * cell, reached by a pointer and by the keyboard alike, with no control inside it. The owner opens
 * Add beside the title, adds a closure of one member from its dialog, and reads it on that member's
 * Staff page; and switches Colour by to Status, a block's hue changing from its member's to its
 * status's chip tone. The owner opens Day on a day both members work and finds a column per
 * scheduled member under one axis, their rows aligned and each hatched outside their hours, opens a
 * column's menu, starts a booking on that member with the wizard's staff pinned, and edits the
 * hours of the day shown from another column's menu, through its entry for that day's hours, the
 * hatch moving as the window is read again.
 *
 * The bookings are written straight to luna's rows on the tenant's today in Athens, or a
 * number of days after it, so the week the calendar opens on holds them whatever day the suite
 * runs, and a walk that needs a member's hours opens on a day that member works; the request
 * holds its window by the database's clock, two days ahead.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** The tenant's today, in Athens. */
function calendarToday(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d');
}

/** The tenant's today a number of days on, or back where the number is negative, in Athens. */
function calendarDay(int $days): string
{
    $today = new DateTimeImmutable(calendarToday(), new DateTimeZone('Europe/Athens'));
    $span = new DateInterval(sprintf('P%dD', abs($days)));

    return ($days < 0 ? $today->sub($span) : $today->add($span))->format('Y-m-d');
}

/**
 * One booking of luna on the member given, starting at the Athens time given on the tenant's
 * today or the day the given number of days after it, or before it where the number is
 * negative: a request on an open approval window, an approved booking awaiting its payment
 * under a live payment hold, or a confirmed booking.
 */
function calendarBooking(string $customer, string $status, string $member, string $time, string $service = 'Haircut', int $days = 0): string
{
    $uuid = (string) Str::uuid();
    $athens = new DateTimeZone('Europe/Athens');
    $day = calendarDay($days);
    $start = new DateTimeImmutable($day.' '.$time, $athens);
    $end = $start->add(new DateInterval('PT60M'));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $request = $status === 'awaiting_approval';
    $unpaid = $status === 'awaiting_payment';
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => $status, 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'customer_phone' => '+30 210 5550101', 'total_minor' => 3500, 'currency' => 'EUR',
        'hold_class' => $request ? 'approval' : ($unpaid ? 'payment' : null),
        'hold_expires_at' => $request || $unpaid ? DB::raw("now() + interval '2 days'") : null,
        'payment_mode' => $unpaid ? 'online' : 'onsite', 'requires_approval' => $request || $unpaid, 'on_approval_timeout' => 'expire',
        'approved_at' => $unpaid ? DB::raw('now()') : null,
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', $service),
        'staff_id' => LunaFixture::id('staff', $member),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => false,
    ]);

    return $uuid;
}

it('shows the week with a working day\'s bookings, one awaiting payment badged as its chip words it, narrows to one member, opens the wizard from a clicked and from a dragged empty slot prefilled, and approves a request on next week from a block\'s dialog, the block staying and losing its badge', function () {
    // Day view below shows Elena alone, so the walk opens on a day she works: today on a
    // weekday, else the Monday after it.
    $days = calendarElenaWorkday();
    $workday = calendarDay($days);
    $cora = calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    $unpaid = calendarBooking('Una Paid', 'awaiting_payment', 'Elena Papadaki', '12:00', days: $days);
    $request = calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', 'Bridal trial', days: $days + 7);
    $elena = (string) LunaFixture::id('staff', 'Elena Papadaki');
    $requestBlock = '.fc-event[data-block-id="'.$request.':0"]';
    $unpaidBlock = '.fc-event[data-block-id="'.$unpaid.':0"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday))
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $page->assertPathIs('/admin/calendar')
        ->assertSeeIn('.admin-calendar', calendarBlock('Bo South', 'Beard trim'))
        ->assertDontSeeIn('.admin-calendar', 'Rita Request')
        ->assertSeeIn($unpaidBlock.' .admin-calendar-badge--payment', adminLine('admin/shell.kit.statuses.awaiting_payment'))
        ->assertSeeIn($unpaidBlock, calendarBlock('Una Paid', 'Haircut'))
        ->assertNotPresent('.fc-event[data-block-id="'.$cora.':0"] .admin-calendar-badge')
        ->assertSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->assertSeeIn('.admin-calendar-legend [data-badge="awaiting_approval"]', adminLine('admin/shell.kit.statuses.awaiting_approval'))
        ->assertSeeIn('.admin-calendar-legend [data-badge="awaiting_payment"]', adminLine('admin/calendar.badges.payment'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.week'));

    // Under Scheduled staff both boxes read ticked, the two shown; clearing Nikos's picks Elena.
    $page->click('#calendar-who')
        ->assertChecked('.admin-calendar-who__member[data-staff-id="'.$elena.'"] input')
        ->click('.admin-calendar-who__member[data-staff-id="'.LunaFixture::id('staff', 'Nikos Georgiou').'"] input')
        ->click('#calendar-who')
        ->assertSeeIn('#calendar-who', 'Elena Papadaki')
        ->assertDontSeeIn('.admin-calendar', 'Bo South')
        ->assertDontSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->assertSeeIn('.admin-calendar', calendarBlock('Cora Cut', 'Haircut'));

    $page->click(adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn($unpaidBlock.' .admin-calendar-badge--payment', adminLine('admin/shell.kit.statuses.awaiting_payment'))
        ->click('.fc-timegrid-slot-lane[data-time="16:00:00"]')
        ->assertVisible('.admin-wizard-strip')
        ->assertAttribute('.admin-wizard-strip li:nth-child(2) .admin-wizard-step', 'value', $workday)
        ->assertValue('#wizard-staff-0', $elena)
        ->click('.admin-wizard-cancel')
        ->assertMissing('.admin-wizard-strip');

    // A drag over empty lanes, from one slot to another, opens the wizard the same way.
    $page->drag('.fc-timegrid-slot-lane[data-time="16:00:00"]', '.fc-timegrid-slot-lane[data-time="17:00:00"]')
        ->assertVisible('.admin-wizard-strip')
        ->assertAttribute('.admin-wizard-strip li:nth-child(2) .admin-wizard-step', 'value', $workday)
        ->assertValue('#wizard-staff-0', $elena)
        ->click('.admin-wizard-cancel')
        ->assertMissing('.admin-wizard-strip');

    $page->click(adminLine('admin/calendar.toolbar.views.week'))
        ->click('.admin-calendar-next')
        ->waitForText(calendarBlock('Rita Request', 'Bridal trial'))
        ->assertSeeIn($requestBlock, adminLine('admin/calendar.badges.pending'))
        ->assertDontSeeIn('.admin-calendar', 'Cora Cut');

    // The action's redirect lands this week and no detail; the screen reads next week, Elena
    // and the open booking back in one visit, so the block stays where it is and the dialog
    // shows the server's answer.
    $page->click($requestBlock)
        ->waitForText('Bridal trial')
        ->assertPathIs('/admin/calendar')
        ->click('.admin-booking-action--approve')
        ->waitForText(adminLine(BookingActionController::APPROVED))
        ->assertPathIs('/admin/calendar')
        ->assertSeeIn('.admin-booking-dialog .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
        ->assertPresent($requestBlock)
        ->assertMissing($requestBlock.' .admin-calendar-badge')
        ->assertDontSeeIn('.admin-calendar', 'Cora Cut');

    expect(DB::table('tenant_luna.bookings')->where('uuid', $request)->value('status'))->toBe('confirmed');
});

it('lands a staff user on the Calendar pinned to Me with their own bookings alone, Who read-only and no slot selection, and their own request opens to Approve with neither email nor phone', function () {
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00');
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim');
    $request = calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', 'Bridal trial');
    addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');

    $page = Admin::signIn($this, 'luna', 'elena@luna.example', 'correct horse battery staple')->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $page->assertPathIs('/admin/calendar')
        ->assertDontSeeIn('.admin-calendar', 'Bo South')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.me'))
        ->assertAttribute('#calendar-who', 'disabled', '')
        ->assertDontSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->assertMissing('.admin-new-booking')
        ->click('.fc-timegrid-slot-lane[data-time="16:00:00"]')
        ->assertMissing('.admin-wizard-strip');

    $page->click('.fc-event[data-block-id="'.$request.':0"]')
        ->waitForText('Bridal trial')
        ->assertPresent('.admin-booking-action--approve')
        ->assertPresent('.admin-booking-action--reject')
        ->assertMissing('.admin-booking-action--cancel')
        ->assertMissing('.admin-booking-facts__email')
        ->assertMissing('.admin-booking-facts__phone')
        ->assertDontSeeIn('.admin-booking-dialog', 'rita-request@example.test')
        ->assertDontSeeIn('.admin-booking-dialog', '+30 210 5550101');
});

it('opens Month on this month\'s six weeks, reads each day\'s counts by status off its chips across two weeks with no legend, and presses a day into Day view', function () {
    // A week apart inside today's month, whatever day of it the suite runs on.
    $apart = (int) substr(calendarToday(), 8, 2) <= 15 ? 7 : -7;
    $today = calendarToday();
    $other = calendarDay($apart);
    $cell = fn (string $day): string => '.admin-calendar-day[data-date="'.$day.'"]';
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00');
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim');
    calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', 'Bridal trial');
    calendarBooking('Nia Next', 'confirmed', 'Nikos Georgiou', '12:00', 'Beard trim', days: $apart);
    calendarBooking('Una Paid', 'awaiting_payment', 'Elena Papadaki', '15:00', days: $apart);

    // A chip is its status's whole counted line, chosen by the count, read as the shell words it.
    $chip = fn (int $count, string $status): string => adminChoice('admin/shell.kit.counted.'.$status, $count);

    // All staff, so the day pressed below shows everyone whatever weekday the suite runs on.
    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $page->click('#calendar-who')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.all'))
        ->assertSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->click(adminLine('admin/calendar.toolbar.views.month'))
        ->waitForText($chip(1, 'confirmed'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.month'))
        ->assertSeeIn('.admin-calendar-title', (new DateTimeImmutable($today, new DateTimeZone('Europe/Athens')))->format('F Y'))
        ->assertSeeIn($cell($today).' .admin-chip--live', $chip(2, 'confirmed'))
        ->assertSeeIn($cell($today).' .admin-chip--attention', $chip(1, 'awaiting_approval'))
        ->assertSeeIn($cell($other).' .admin-chip--live', $chip(1, 'confirmed'))
        ->assertNotPresent($cell($other).' .admin-chip--attention')
        ->assertNotPresent($cell(calendarDay($apart > 0 ? 1 : -1)).' .admin-chip')
        ->assertSeeIn($cell($other).' .admin-chip--provisional', $chip(1, 'awaiting_payment'))
        ->assertNotPresent('.fc-event')
        ->assertNotPresent('.admin-calendar-badge')
        ->assertNotPresent('.admin-calendar-legend')
        ->assertPresent('td'.$cell($today).'[tabindex="0"]')
        ->assertNotPresent('.admin-calendar-day button')
        ->assertNotPresent('.fc-daygrid-day-number[href]');

    // Month offers no slot selection: the press on the cell opens Day view and never the wizard.
    $page->click($cell($other))
        ->waitForText(calendarBlock('Nia Next', 'Beard trim'))
        ->assertMissing('.admin-wizard-strip')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertNotPresent('.admin-calendar-day')
        ->assertDontSeeIn('.admin-calendar', 'Cora Cut')
        ->assertSeeIn('.admin-calendar-legend', 'Nikos Georgiou');

    // The keyboard reaches the same press: Enter on today's cell opens Day view on today.
    $page->click(adminLine('admin/calendar.toolbar.views.month'))
        ->waitForText($chip(2, 'confirmed'))
        ->keys($cell($today), 'Enter')
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertMissing('.admin-wizard-strip')
        ->assertDontSeeIn('.admin-calendar', 'Nia Next');
});

it('adds a member\'s closure from the Add menu beside the title and shows it on that member\'s Staff page', function () {
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $today = calendarToday();

    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'));
    $page->click('#calendar-who')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.all'))
        ->assertPresent('.admin-new-booking')
        ->click('.admin-calendar-add')
        ->assertSeeIn('.admin-menu', adminLine('admin/calendar.page.add_booking'))
        ->click('.admin-calendar-add__closure')
        ->waitForText(adminLine('admin/calendar.closure.title'))
        ->assertSeeIn('.admin-closure .admin-modal__title', adminLine('admin/calendar.closure.title'))
        ->select('#closure-who', (string) $elena)
        ->click('.admin-closure__date')
        ->click(sprintf('button[data-date="%s"]', $today))
        ->type('#closure-reason', 'Training')
        ->click('.admin-closure__add')
        ->waitForText(adminLine(BlackoutController::ADDED))
        ->assertMissing('.admin-closure');

    expect(DB::table('tenant_luna.blackouts')->where('staff_id', $elena)->where('date', $today)->value('reason'))->toBe('Training');

    $page->navigate(Admin::url('luna', '/staff/'.$elena))
        ->waitForText(adminLine('admin/staff.page.exceptions'))
        ->assertSeeIn('.admin-blackouts', (new DateTimeImmutable($today, new DateTimeZone('Europe/Athens')))->format('d/m/Y'))
        ->assertSeeIn('.admin-blackouts', 'Training');
});

it('switches Colour by to Status from the filter card, the block\'s hue changing from its member\'s to its status\'s and the legend listing the statuses', function () {
    $cora = calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00');
    $block = '.fc-event[data-block-id="'.$cora.':0"]';
    $hue = fn (): string => "document.querySelector('{$block}').style.getPropertyValue('--admin-block-hue')";

    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $before = $page->assertValue('.admin-calendar-colour-by', 'staff')->script($hue());

    expect($before)->toBeString()->toStartWith('#');

    $page->assertSeeIn('.admin-calendar-legend', 'Elena Papadaki')
        ->select('.admin-calendar-colour-by', 'status')
        ->waitForText(adminLine(SettingsController::COLOUR_BY_SAVED))
        ->assertValue('.admin-calendar-colour-by', 'status')
        ->assertPresent('.admin-calendar-legend[data-colour-by="status"]')
        ->assertScript($hue(), 'var(--admin-status-live)')
        ->assertSeeIn('.admin-calendar-legend', adminLine('admin/shell.kit.statuses.confirmed'))
        ->assertDontSeeIn('.admin-calendar-legend', 'Elena Papadaki');

    expect(DB::table('tenant_luna.settings')->value('colour_by'))->toBe('status');
});

/**
 * The next Wednesday after the tenant's today, in days from it: a day luna's two members both
 * work.
 */
function calendarNextWednesday(): int
{
    $weekday = (int) (new DateTimeImmutable(calendarToday(), new DateTimeZone('Europe/Athens')))->format('N');

    return (3 - $weekday + 7) % 7 ?: 7;
}

/**
 * The first day from the tenant's today that Elena works, in days from it: 0 on Monday to
 * Friday, her week in the demo hours, else the days to the Monday after.
 */
function calendarElenaWorkday(): int
{
    $weekday = (int) (new DateTimeImmutable(calendarToday(), new DateTimeZone('Europe/Athens')))->format('N');

    return $weekday <= 5 ? 0 : 8 - $weekday;
}

/** A block's title as the grid reads it, its customer's name and its service's. */
function calendarBlock(string $customer, string $service): string
{
    return adminLine('admin/calendar.grid.block', ['customer' => $customer, 'service' => $service]);
}

/** A Day column's selector by its member. */
function calendarColumn(int $staffId): string
{
    return '.admin-calendar-column[data-column="member-'.$staffId.'"]';
}

it('opens Day as a column per scheduled member under one axis, each hatched outside their hours, starts a booking on a member from a column\'s menu with the wizard\'s staff pinned, and edits a member\'s hours of the day from it, the hatch moving', function () {
    $days = calendarNextWednesday();
    $wednesday = calendarDay($days);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    calendarBooking('Dee Next', 'confirmed', 'Nikos Georgiou', '15:00', days: $days + 1);
    $thursday = calendarDay($days + 1);
    $hatches = fn (int $staffId): string => "document.querySelectorAll('".calendarColumn($staffId)." .fc-non-business').length";
    $date = (new DateTimeImmutable($wednesday, new DateTimeZone('Europe/Athens')))->format('d/m/Y');
    // The column menu's title names the day as Day's title does, its weekday first.
    $day = adminLine('admin/calendar.toolbar.day_title', ['weekday' => (new DateTimeImmutable($wednesday, new DateTimeZone('Europe/Athens')))->format('l'), 'date' => $date]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$wednesday))
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'));

    $page->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertCount('.admin-calendar-column', 2)
        ->assertSeeIn(calendarColumn($elena).' .admin-calendar-head__name', 'Elena Papadaki')
        ->assertSeeIn(calendarColumn($elena).' .admin-calendar-head__hours', '09:00-13:00, 14:00-18:00')
        ->assertSeeIn(calendarColumn($nikos).' .admin-calendar-head__hours', '10:00-19:00')
        ->assertSeeIn(calendarColumn($elena), calendarBlock('Cora Cut', 'Haircut'))
        ->assertSeeIn(calendarColumn($nikos), calendarBlock('Bo South', 'Beard trim'))
        // The axis labels on the first column alone, and the rows of both at the same height.
        ->assertScript("[...document.querySelectorAll('.admin-calendar-column')].map((column) => [...column.querySelectorAll('.fc-timegrid-slot-label-cushion')].map((label) => label.textContent).join('') !== '').join(',')", 'true,false')
        ->assertScript("(() => { const [a, b] = [...document.querySelectorAll('.admin-calendar-column .fc-timegrid-slot-lane[data-time=\"12:00:00\"]')]; return a !== undefined && b !== undefined && Math.abs(a.getBoundingClientRect().top - b.getBoundingClientRect().top) < 1; })()", true)
        // Elena outside 09:00-13:00 and 14:00-18:00 on the 08:00-20:00 axis: three hatches;
        // Nikos two.
        ->assertScript($hatches($elena), 3)
        ->assertScript($hatches($nikos), 2)
        ->assertSeeIn('.admin-calendar-legend__closure', adminLine('admin/calendar.legend.closure'))
        ->assertSeeIn('.admin-calendar-legend__outside', adminLine('admin/calendar.legend.outside'));

    $page->click(calendarColumn($nikos).' .admin-calendar-head__menu')
        ->assertSeeIn('.admin-menu__title', adminLine('admin/calendar.day.menu', ['staff' => 'Nikos Georgiou', 'date' => $day]))
        ->assertSeeIn('.admin-menu', adminLine('admin/calendar.day.close'))
        ->assertSeeIn('.admin-menu', adminLine('admin/calendar.day.staff_page'))
        ->click('.admin-calendar-column__book')
        ->assertVisible('.admin-wizard-strip')
        ->assertAttribute('.admin-wizard-strip li:nth-child(2) .admin-wizard-step', 'value', $wednesday)
        ->assertValue('#wizard-staff-0', (string) $nikos)
        ->click('.admin-wizard-cancel')
        ->assertMissing('.admin-wizard-strip');

    // An empty slot of the second column hands that column's member, not the first's nor Any staff.
    $page->assertScript("document.querySelectorAll('.admin-calendar-column')[1]?.dataset.column", 'member-'.$nikos)
        ->click(calendarColumn($nikos).' .fc-timegrid-slot-lane[data-time="16:00:00"]')
        ->assertVisible('.admin-wizard-strip')
        ->assertAttribute('.admin-wizard-strip li:nth-child(2) .admin-wizard-step', 'value', $wednesday)
        ->assertValue('#wizard-staff-0', (string) $nikos)
        ->click('.admin-wizard-cancel')
        ->assertMissing('.admin-wizard-strip');

    // Elena's afternoon dropped for the day: the hatch now runs from 13:00 to the axis's end.
    $page->click(calendarColumn($elena).' .admin-calendar-head__menu')
        ->click('.admin-calendar-column__edit')
        ->assertVisible('.admin-shift-day')
        ->click('.admin-shift-day__window[data-window="1"] .admin-shift-day__remove')
        ->click('.admin-shift-day__save')
        ->waitForText(adminLine('admin/shifts.flashes.day_saved'))
        ->assertMissing('.admin-shift-day')
        ->assertSeeIn(calendarColumn($elena).' .admin-calendar-head__hours', '09:00-13:00')
        ->assertDontSeeIn(calendarColumn($elena).' .admin-calendar-head__hours', '14:00-18:00')
        ->assertScript($hatches($elena), 2)
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn(calendarColumn($elena), calendarBlock('Cora Cut', 'Haircut'));

    // Next in Day moves every column to Thursday: its block drawn in its member's column, every
    // column on the new date and no block of Wednesday's left.
    $page->click('.admin-calendar-next')
        ->waitForText(calendarBlock('Dee Next', 'Haircut'))
        ->assertSeeIn(calendarColumn($nikos), calendarBlock('Dee Next', 'Haircut'))
        ->assertScript("[...document.querySelectorAll('.admin-calendar-column .fc-timegrid-col[data-date]')].map((column) => column.dataset.date).join(',')", $thursday.','.$thursday)
        ->assertDontSee(calendarBlock('Cora Cut', 'Haircut'))
        ->assertSeeIn(calendarColumn($elena).' .admin-calendar-head__hours', '09:00-13:00, 14:00-18:00')
        ->assertNoJavaScriptErrors();

    $windows = DB::table('tenant_luna.shift_overrides')->where('staff_id', $elena)->where('date', $wednesday)->value('windows');

    expect(is_string($windows) ? json_decode($windows, true) : null)->toEqual([['start' => '09:00', 'end' => '13:00']]);
});
