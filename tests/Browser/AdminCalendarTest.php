<?php

use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\SettingsController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Page;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Support\LunaFixture;

/*
 * The Calendar in a real browser: an owner signs in and opens the week of a day Elena works, a row
 * per member with each day's bookings as chips, picks one member under Who and finds the week her
 * time grid with the other's booking gone, opens the wizard from an empty slot of Day prefilled
 * with that day and the member pinned, by a click and by a drag alike,
 * moves to next week, opens a block there into the dialog and approves the request from it, the
 * block staying on next week's grid and losing its badge as the window wanted and the booking open
 * are re-read together, since the action's redirect lands the default week; a staff user signs in,
 * lands on the Calendar pinned to Me with their own bookings alone, Who read-only, opens their own
 * request to Approve and Reject with neither email nor phone, and finds no slot selection. Month:
 * the owner opens the month on bookings a week apart, a chip per booking with its time, its
 * customer and its marks, the first two of a day and how many more, a day the business is closed
 * hatched with its chips kept, and the legend naming the marks; opens a booking from a chip and
 * from the popover of a day's line, which Escape closes; and from a day's date menu, reached by a
 * pointer and by the keyboard alike, starts a booking on that date and opens the day. Week's rows,
 * on a Wednesday both members work: a member's day lists its first six bookings and how many
 * more, whose popover is titled with the member and the day and opens a booking; a closure of part
 * of the day sits among the chips as a line with its times and reason, a member's day off is
 * hatched, a pointer over a day marks its row's head and its day's head, a day's date menu reached
 * by the keyboard starts a booking on that member and date, and a day's head opens Day on it. A
 * booking awaiting payment wears its own outline on the week's chips and its badge on the day
 * grid, the legend naming both, and its outline on Month. The owner opens Add beside the title,
 * adds a closure of one member from its dialog, and reads it on that member's Staff page; and
 * switches Colour by to Status, a booking's hue changing from its member's to its status's chip
 * tone. The owner opens Day
 * on a day both members work and finds a column per scheduled member under one axis, their rows
 * aligned and each hatched outside their hours, opens a column's menu, starts a booking on that
 * member with the wizard's staff pinned, and edits the hours of the day shown from another
 * column's menu, through its entry for that day's hours, the hatch moving as the window is read
 * again. From a column's Closure here, its date prefilled, the owner picks an Until two days on,
 * unticks Closed all day and adds the run, and finds it drawn on that member's column on each of
 * the three days.
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

it('shows the week as a row per member with a working day\'s bookings, one awaiting payment outlined as its legend words it, narrows to one member\'s time grid, opens the wizard from a clicked and from a dragged empty slot prefilled, and approves a request on next week from a block\'s dialog, the block staying and losing its badge', function () {
    // Day view below shows Elena alone, so the walk opens on a day she works: today on a
    // weekday, else the Monday after it.
    $days = calendarElenaWorkday();
    $workday = calendarDay($days);
    $cora = calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    $bo = calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    $unpaid = calendarBooking('Una Paid', 'awaiting_payment', 'Elena Papadaki', '12:00', days: $days);
    $request = calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', 'Bridal trial', days: $days + 7);
    $elena = (string) LunaFixture::id('staff', 'Elena Papadaki');
    $requestBlock = '.fc-event[data-block-id="'.$request.':0"]';
    $unpaidBlock = '.fc-event[data-block-id="'.$unpaid.':0"]';
    $chip = fn (string $uuid): string => '.admin-calendar-chip[data-uuid="'.$uuid.'"]';

    // Both members work this week, so Week opens as a row per member, each day listing its chips.
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday))
        ->waitForText('Cora Cut');
    $page->assertPathIs('/admin/calendar')
        ->assertSeeIn(calendarCell((int) $elena, $workday).' '.$chip($cora).' .admin-calendar-chip__customer', 'Cora Cut')
        ->assertSeeIn(calendarCell(LunaFixture::id('staff', 'Nikos Georgiou'), $workday).' '.$chip($bo).' .admin-calendar-chip__customer', 'Bo South')
        ->assertDontSeeIn('.admin-calendar', 'Rita Request')
        ->assertAttribute(calendarCell((int) $elena, $workday).' '.$chip($unpaid), 'data-badge', 'awaiting_payment')
        ->assertSeeIn($chip($unpaid).' .admin-calendar-chip__customer', 'Una Paid')
        ->assertNotPresent($chip($cora).'[data-badge]')
        ->assertNotPresent('.fc-event')
        ->assertSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->assertSeeIn('.admin-calendar-legend [data-badge="awaiting_approval"]', adminLine('admin/shell.kit.statuses.awaiting_approval'))
        ->assertSeeIn('.admin-calendar-legend [data-badge="awaiting_payment"]', adminLine('admin/calendar.badges.payment'))
        ->assertSeeIn('.admin-calendar-legend__noted', adminLine('admin/calendar.badges.noted'))
        ->assertSeeIn('.admin-calendar-legend__closure', adminLine('admin/calendar.legend.closure'))
        ->assertNotPresent('.admin-calendar-legend__closed')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.week'));

    // Under Scheduled staff both boxes read ticked, the two shown; clearing Nikos's picks Elena,
    // and the week becomes her time grid, its bookings as blocks, the badge on the block.
    $page->click('#calendar-who')
        ->assertChecked('.admin-calendar-who__member[data-staff-id="'.$elena.'"] input')
        ->click('.admin-calendar-who__member[data-staff-id="'.LunaFixture::id('staff', 'Nikos Georgiou').'"] input')
        ->click('#calendar-who')
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertSeeIn('#calendar-who', 'Elena Papadaki')
        ->assertDontSeeIn('.admin-calendar', 'Bo South')
        ->assertDontSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->assertNotPresent('.admin-calendar-week')
        ->assertSeeIn($unpaidBlock.' .admin-calendar-badge--payment', adminLine('admin/shell.kit.statuses.awaiting_payment'))
        ->assertSeeIn($unpaidBlock, calendarBlock('Una Paid', 'Haircut'))
        ->assertNotPresent('.fc-event[data-block-id="'.$cora.':0"] .admin-calendar-badge')
        ->assertSeeIn('.admin-calendar-legend__inside', adminLine('admin/calendar.badges.closure'));

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
    // An empty slot is clicked below, and a day Elena holds no hours on is hatched over every
    // slot, so the bookings stand on a day she works: today on a weekday, else the Monday after.
    $days = calendarElenaWorkday();
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    $request = calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', 'Bridal trial', days: $days);
    addTenantUser('elena@luna.example', 'staff', staff: 'Elena Papadaki');

    // The sign-in lands on the Calendar, pinned; that day's Day is then opened by its address.
    $page = Admin::signedIn($this, 'luna', 'elena@luna.example', 'correct horse battery staple');
    $page->assertPathIs('/admin/calendar')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.me'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'));
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

it('opens Month on this month\'s six weeks, a chip per booking with its marks and how many more, a closed day hatched and full, opens a booking from a chip and from a day\'s popover, and from a day\'s date menu starts a booking on that date and opens the day', function () {
    // A week apart inside today's month, whatever day of it the suite runs on.
    $apart = (int) substr(calendarToday(), 8, 2) <= 15 ? 7 : -7;
    $today = calendarToday();
    $other = calendarDay($apart);
    $empty = calendarDay($apart > 0 ? 1 : -1);
    $cell = fn (string $day): string => '.admin-calendar-month__day[data-date="'.$day.'"]';
    $chip = fn (string $uuid): string => '.admin-calendar-chip[data-uuid="'.$uuid.'"]';
    $cora = calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00');
    $bo = calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim');
    $rita = calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', 'Bridal trial');
    $nia = calendarBooking('Nia Next', 'confirmed', 'Nikos Georgiou', '12:00', 'Beard trim', days: $apart);
    $una = calendarBooking('Una Paid', 'awaiting_payment', 'Elena Papadaki', '15:00', days: $apart);
    DB::table('tenant_luna.booking_meta')->insert(['booking_id' => DB::table('tenant_luna.bookings')->where('uuid', $cora)->value('id'), 'key' => 'note', 'value' => 'Please use the side door']);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => $other, 'start_time' => null, 'end_time' => null, 'reason' => 'Holiday']);

    // All staff, so the days opened below show everyone whatever weekday the suite runs on.
    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))->waitForText('Cora Cut');
    $page->click('#calendar-who')
        ->click('.admin-calendar-who__preset[data-who="all"]')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.all'))
        ->assertSeeIn('.admin-calendar-legend', 'Nikos Georgiou')
        ->click(adminLine('admin/calendar.toolbar.views.month'))
        ->waitForText(adminChoice('admin/calendar.cells.more', 1))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.month'))
        ->assertSeeIn('.admin-calendar-title', (new DateTimeImmutable($today, new DateTimeZone('Europe/Athens')))->format('F Y'))
        ->assertSeeIn($cell($today).' '.$chip($cora).' .admin-calendar-chip__customer', 'Cora Cut')
        ->assertPresent($cell($today).' '.$chip($cora).' .admin-calendar-chip__noted')
        ->assertPresent($cell($today).' '.$chip($bo))
        ->assertNotPresent($cell($today).' '.$chip($rita))
        ->assertSeeIn($cell($today).' .admin-calendar-more', adminChoice('admin/calendar.cells.more', 1))
        ->assertPresent($cell($today).' .admin-calendar-month__number--today')
        ->assertPresent($cell($other).'.admin-calendar-month__day--closed')
        ->assertPresent($cell($other).' '.$chip($nia))
        ->assertAttribute($cell($other).' '.$chip($una), 'data-badge', 'awaiting_payment')
        ->assertNotPresent($cell($other).' .admin-calendar-more')
        ->assertNotPresent($cell($empty).' .admin-calendar-chip')
        ->assertNotPresent('.fc-event')
        ->assertSeeIn('.admin-calendar-legend [data-badge="awaiting_payment"]', adminLine('admin/calendar.badges.payment'))
        ->assertSeeIn('.admin-calendar-legend__noted', adminLine('admin/calendar.badges.noted'))
        ->assertSeeIn('.admin-calendar-legend__closed', adminLine('admin/calendar.closure.all_day'));

    // A chip opens its booking, and Month stays under the dialog.
    $page->click($chip($cora))
        ->waitForText('Please use the side door')
        ->click('.admin-booking-close')
        ->assertMissing('.admin-booking-dialog')
        ->assertScript(Admin::focusIsOn($cell($today).' '.$chip($cora)), true)
        ->assertPresent($cell($today).' '.$chip($cora));

    // The day's line opens the popover of all its chips under the day's title; Escape closes it
    // and hands focus back to the line, and a row opens its booking, closing the popover.
    $page->click($cell($today).' .admin-calendar-more')
        ->assertSeeIn('.admin-calendar-popover__title', (new DateTimeImmutable($today, new DateTimeZone('Europe/Athens')))->format('l'))
        ->assertCount('.admin-calendar-popover .admin-calendar-chip', 3)
        ->assertAttribute('.admin-calendar-popover '.$chip($rita), 'data-badge', 'awaiting_approval')
        ->keys('.admin-calendar-popover '.$chip($rita), 'Escape')
        ->assertMissing('.admin-calendar-popover')
        ->assertScript(Admin::focusIsOn($cell($today).' .admin-calendar-more'), true)
        ->click($cell($today).' .admin-calendar-more')
        ->click('.admin-calendar-popover '.$chip($rita))
        ->waitForText('Bridal trial')
        ->assertMissing('.admin-calendar-popover')
        ->click('.admin-booking-close')
        ->assertMissing('.admin-booking-dialog')
        ->assertScript(Admin::focusIsOn($cell($today).' .admin-calendar-more'), true);

    // The popover opens on a page it leaves as it lay: in a window too short for the month, the
    // line scrolled to the window's middle, neither the page nor the day moves as the list
    // opens, and the page is left free to scroll under it.
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1280, 620)
        ->script("document.querySelector('".$cell($today)." .admin-calendar-more').scrollIntoView({ block: 'center' })");
    $lay = $page->script(calendarLieOf($cell($today)));
    $page->click($cell($today).' .admin-calendar-more')
        ->assertCount('.admin-calendar-popover .admin-calendar-chip', 3)
        ->assertScript(calendarLieOf($cell($today)), $lay);
    $page->keys('.admin-calendar-popover '.$chip($rita), 'Escape')
        ->assertMissing('.admin-calendar-popover')
        ->assertScript(Admin::focusIsOn($cell($today).' .admin-calendar-more'), true)
        ->resize($size['width'], $size['height'])
        ->script('window.scrollTo(0, 0)');

    // A press outside the open popover closes it and does no more, on a chip and on an empty
    // day alike: no booking opens, no date menu, Month stays, and focus is back on the line.
    // The chip is one of the day's own, over its line, where the popover hung under the line
    // never lies. A chip of the day a week on would lie under the popover, and the press would
    // fall on the list.
    foreach ([$cell($today).' '.$chip($cora), $cell($empty).' .admin-calendar-date'] as $outside) {
        $page->click($cell($today).' .admin-calendar-more')
            ->assertCount('.admin-calendar-popover .admin-calendar-chip', 3);
        calendarPress($page, $outside);
        $page->assertMissing('.admin-calendar-popover')
            ->assertScript(Admin::focusIsOn($cell($today).' .admin-calendar-more'), true)
            ->assertMissing('.admin-booking-dialog')
            ->assertMissing('.admin-menu')
            ->assertMissing('.admin-wizard-strip')
            ->assertPresent('.admin-calendar-month')
            ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.month'));
    }

    // A press on a day opens its date menu, whose New booking opens the wizard on that date.
    $page->click($cell($empty).' .admin-calendar-date')
        ->assertSeeIn('.admin-calendar-date__book', adminLine('admin/calendar.page.add_booking'))
        ->assertSeeIn('.admin-calendar-date__closure', adminLine('admin/calendar.closure.title'))
        ->click('.admin-calendar-date__book')
        ->assertVisible('.admin-wizard-strip')
        ->assertAttribute('.admin-wizard-strip li:nth-child(2) .admin-wizard-step', 'value', $empty)
        ->click('.admin-wizard-cancel')
        ->assertMissing('.admin-wizard-strip')
        ->assertScript(Admin::focusIsOn($cell($empty).' .admin-calendar-date'), true);

    // The list opens where the press fell and off it, so the press ending there picks nothing:
    // under a press high in a day of the first week, room below it, and over one low in a day
    // of the last week, the window scrolled to leave no room for a list under it. Escape hands
    // focus back to the day, a moment after the list is gone and bringing the day into view, so
    // the walk reads it there before it scrolls the page.
    // Each day is the first of its week, or the second where the first is today: both are
    // pressed low as well, and low in today lies its "1 more" line, which would take the press
    // and open its popover. A day with no line is bare there.
    $from = fn (string $first, string $second): string => '.admin-calendar-month__day:'.($page->script("document.querySelector('.admin-calendar-month__day:{$first}').dataset.date") === $today ? $second : $first).' .admin-calendar-date';
    $high = $from('first-child', 'nth-child(2)');
    $low = $from('nth-last-child(7)', 'nth-last-child(6)');
    $at = ['x' => 40, 'y' => 12];
    calendarPress($page, $high, $at);
    $page->assertScript(calendarListAbout($high, $at), 'under')
        ->assertCount('.admin-menu__item', 3)
        ->assertMissing('.admin-wizard-strip')
        ->keys('.admin-menu', 'Escape')
        ->assertMissing('.admin-menu')
        ->assertScript(Admin::focusIsOn($high), true);

    // A second press on the same day hangs the list from where that press landed, low at the
    // day's far side, and not from the first. A press on a day of another week with that list
    // up closes it and opens that day's own: one list, at that press.
    $next = '.admin-calendar-month__day:nth-child(12) .admin-calendar-date';
    $frame = calendarPlaywright($page)->locator($high)->boundingBox();
    $again = ['x' => (int) ($frame['width'] ?? 0) - 12, 'y' => (int) ($frame['height'] ?? 0) - 10];
    expect($again['x'])->toBeGreaterThan($at['x'] + 40);
    expect($again['y'])->toBeGreaterThan($at['y'] + 40);
    calendarPress($page, $high, $again);
    $page->assertScript(calendarListAbout($high, $again), 'under')
        ->assertCount('.admin-menu__item', 3);
    calendarPress($page, $next, $at);
    $page->assertScript(calendarListAbout($next, $at), 'under')
        ->assertCount('.admin-menu', 1)
        ->assertCount('.admin-menu__item', 3)
        ->assertMissing('.admin-wizard-strip')
        ->assertMissing('.admin-booking-dialog')
        ->keys('.admin-menu', 'Escape')
        ->assertMissing('.admin-menu')
        ->assertScript(Admin::focusIsOn($next), true)
        ->resize($size['width'], 480);
    $deep = ['x' => 40, 'y' => (int) (calendarPlaywright($page)->locator($low)->boundingBox()['height'] ?? 0) - 10];
    expect($deep['y'])->toBeGreaterThan($at['y']);
    $page->assertScript(calendarLowInWindow($low, $deep), true);
    calendarPress($page, $low, $deep);
    $page->assertScript(calendarListAbout($low, $deep), 'over')
        ->assertCount('.admin-menu__item', 3)
        ->assertMissing('.admin-wizard-strip')
        ->assertMissing('.admin-closure__add')
        ->assertPresent('.admin-calendar-month')
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.month'))
        ->keys('.admin-menu', 'Escape')
        ->assertMissing('.admin-menu')
        ->assertScript(Admin::focusIsOn($low), true)
        ->resize($size['width'], $size['height']);

    // The keyboard reaches the same menu: Enter on a day's cell, then Open day, opens Day view on it.
    $page->keys($cell($other).' .admin-calendar-date', 'Enter')
        ->click('.admin-calendar-date__day')
        ->waitForText(calendarBlock('Nia Next', 'Beard trim'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertMissing('.admin-wizard-strip')
        ->assertNotPresent('.admin-calendar-month')
        ->assertDontSeeIn('.admin-calendar', 'Cora Cut')
        ->assertSeeIn('.admin-calendar-legend', 'Nikos Georgiou');
});

it('shows Week as a row per member: a day\'s first six bookings and how many more in a popover, a closure line, a day off hatched, the heads marked under the pointer, a day\'s date menu by the keyboard, and a day\'s head opening Day', function () {
    $days = calendarNextWednesday();
    $wednesday = calendarDay($days);
    $saturday = calendarDay($days + 3);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $chip = fn (string $uuid): string => '.admin-calendar-chip[data-uuid="'.$uuid.'"]';
    $mine = calendarCell($elena, $wednesday);
    $times = ['09:00' => 'Ada Early', '10:00' => 'Cora Cut', '11:00' => 'Dan Mid', '12:00' => 'Eve Noon', '14:00' => 'Fay After', '15:00' => 'Gil Late', '16:00' => 'Hal Last'];
    $uuids = [];

    foreach ($times as $time => $customer) {
        $uuids[$customer] = calendarBooking($customer, 'confirmed', 'Elena Papadaki', $time, days: $days);
    }

    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => $wednesday, 'start_time' => '17:00', 'end_time' => '18:00', 'reason' => 'Training']);
    $title = adminLine('admin/calendar.day.menu', [
        'staff' => 'Elena Papadaki',
        'date' => adminLine('admin/calendar.toolbar.day_title', ['weekday' => 'Wednesday', 'date' => (new DateTimeImmutable($wednesday, new DateTimeZone('Europe/Athens')))->format('d/m/Y')]),
    ]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$wednesday))
        ->waitForText('Cora Cut');
    $page->assertCount('.admin-calendar-week__person', 2)
        ->assertSeeIn('.admin-calendar-week__person[data-row="member-'.$elena.'"]', 'Elena Papadaki')
        ->assertCount($mine.' .admin-calendar-chip', 6)
        ->assertNotPresent($mine.' '.$chip($uuids['Hal Last']))
        ->assertSeeIn($mine.' .admin-calendar-more', adminChoice('admin/calendar.cells.more', 1))
        ->assertSeeIn($mine.' .admin-calendar-week__closure span:nth-child(2)', '17:00 - 18:00')
        ->assertSeeIn($mine.' .admin-calendar-week__closure span:nth-child(3)', 'Training')
        ->assertAttribute($mine.' .admin-calendar-week__closure', 'title', adminLine('admin/shifts.page.closed_reason', ['start' => '17:00', 'end' => '18:00', 'reason' => 'Training']))
        ->assertSeeIn(calendarCell($nikos, $wednesday).' .admin-calendar-chip__customer', 'Bo South')
        ->assertPresent(calendarCell($elena, $saturday).'.admin-calendar-week__cell--hatched')
        ->assertNotPresent($mine.'.admin-calendar-week__cell--hatched')
        ->assertNotPresent(calendarCell($nikos, $saturday).'.admin-calendar-week__cell--hatched')
        ->assertNotPresent('.admin-calendar-week__person--marked');

    // The pointer over a day marks its row's head and its day's head.
    $page->hover(calendarCell($nikos, $saturday))
        ->assertPresent('.admin-calendar-week__person--marked[data-row="member-'.$nikos.'"]')
        ->assertPresent('.admin-calendar-week__day--marked[data-day="'.$saturday.'"]')
        ->assertCount('.admin-calendar-week__person--marked', 1)
        ->assertCount('.admin-calendar-week__day--marked', 1);

    // How many more opens the popover of all the day's chips under the member and the day; a
    // row opens its booking, closing the popover.
    $page->click($mine.' .admin-calendar-more')
        ->assertSeeIn('.admin-calendar-popover__title', $title)
        ->assertCount('.admin-calendar-popover .admin-calendar-chip', 7)
        ->click('.admin-calendar-popover '.$chip($uuids['Hal Last']))
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-dialog', 'Hal Last')
        ->assertMissing('.admin-calendar-popover')
        ->click('.admin-booking-close')
        ->assertMissing('.admin-booking-dialog')
        ->assertScript(Admin::focusIsOn($mine.' .admin-calendar-more'), true);

    // A press outside the open popover closes it and does no more: no booking opens from the
    // chip pressed, no date menu from the day, no Day view from a day's head, and focus is back
    // on the line. A pull on a chip of the page is such a press too: the popover closes and the
    // chip stays in its day, nothing asked.
    $first = $mine.' '.$chip($uuids['Ada Early']);
    foreach ([$first, calendarCell($nikos, $saturday).' .admin-calendar-date', '.admin-calendar-week__day[data-day="'.$saturday.'"]'] as $outside) {
        $page->click($mine.' .admin-calendar-more')
            ->assertCount('.admin-calendar-popover .admin-calendar-chip', 7);
        calendarPress($page, $outside);
        $page->assertMissing('.admin-calendar-popover')
            ->assertScript(Admin::focusIsOn($mine.' .admin-calendar-more'), true)
            ->assertMissing('.admin-booking-dialog')
            ->assertMissing('.admin-menu')
            ->assertPresent('.admin-calendar-week');
    }
    $page->click($mine.' .admin-calendar-more')
        ->assertCount('.admin-calendar-popover .admin-calendar-chip', 7);
    calendarPlaywright($page)->locator($first)->dragTo(calendarPlaywright($page)->locator(calendarCell($nikos, $saturday)), ['sourcePosition' => ['x' => 8, 'y' => 6], 'targetPosition' => ['x' => 12, 'y' => 8], 'steps' => 12, 'force' => true]);
    $page->assertMissing('.admin-calendar-popover')
        ->assertScript(Admin::focusIsOn($mine.' .admin-calendar-more'), true)
        ->assertNotPresent('.admin-move-confirm')
        ->assertMissing('.admin-menu')
        ->assertPresent($first)
        ->assertCount($mine.' .admin-calendar-chip', 6);

    // A press on a day's free space opens its date menu where it fell: in the margin of a day
    // full of chips, and low in an empty day of the same tall row with the window leaving no
    // room under it, the list then over the press and the press picking nothing. Escape hands
    // focus back to the day, which the walk reads before it scrolls the page. A press on a
    // closure's line opens no menu.
    $full = $mine.' .admin-calendar-date';
    $bare = calendarCell($elena, calendarDay($days + 1)).' .admin-calendar-date';
    $margin = ['x' => 2, 'y' => 2];
    calendarPress($page, $full, $margin);
    $page->assertScript(calendarListAbout($full, $margin), 'under')
        ->assertSeeIn('.admin-menu__title', $title)
        ->assertCount('.admin-menu__item', 3);
    Look::shot($page, 'menu-week-row-date');
    $page->keys('.admin-menu', 'Escape')
        ->assertMissing('.admin-menu')
        ->assertScript(Admin::focusIsOn($full), true);
    $height = calendarPlaywright($page)->locator($bare)->boundingBox()['height'] ?? 0;
    expect($height)->toBeGreaterThan(120);
    $deep = ['x' => 30, 'y' => (int) $height - 10];
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize($size['width'], 480)
        ->assertScript(calendarLowInWindow($bare, $deep), true);
    calendarPress($page, $bare, $deep);
    $page->assertScript(calendarListAbout($bare, $deep), 'over')
        ->assertCount('.admin-menu__item', 3)
        ->assertMissing('.admin-wizard-strip')
        ->assertMissing('.admin-closure__add')
        ->assertPresent('.admin-calendar-week')
        ->keys('.admin-menu', 'Escape')
        ->assertMissing('.admin-menu')
        ->assertScript(Admin::focusIsOn($bare), true)
        ->resize($size['width'], $size['height']);
    calendarPress($page, $mine.' .admin-calendar-week__closure');
    $page->assertMissing('.admin-menu');

    // Enter on a day of Nikos's row opens its date menu, whose New booking opens the wizard on
    // that date with Nikos.
    $page->keys(calendarCell($nikos, $wednesday).' .admin-calendar-date', 'Enter')
        ->assertSeeIn('.admin-calendar-date__book', adminLine('admin/calendar.page.add_booking'))
        ->assertSeeIn('.admin-calendar-date__closure', adminLine('admin/calendar.closure.title'))
        ->assertSeeIn('.admin-calendar-date__day', adminLine('admin/calendar.cells.open_day'))
        ->click('.admin-calendar-date__book')
        ->assertVisible('.admin-wizard-strip')
        ->assertAttribute('.admin-wizard-strip li:nth-child(2) .admin-wizard-step', 'value', $wednesday)
        ->assertValue('#wizard-staff-0', (string) $nikos)
        ->click('.admin-wizard-cancel')
        ->assertMissing('.admin-wizard-strip')
        ->assertScript(Admin::focusIsOn(calendarCell($nikos, $wednesday).' .admin-calendar-date'), true);

    // A day's head opens Day view on it, a column per member working it.
    $page->click('.admin-calendar-week__day[data-day="'.$wednesday.'"]')
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertNotPresent('.admin-calendar-week')
        ->assertSeeIn(calendarColumn($elena), calendarBlock('Hal Last', 'Haircut'))
        ->assertSeeIn(calendarColumn($nikos), calendarBlock('Bo South', 'Beard trim'))
        ->assertNoJavaScriptErrors();
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
        ->assertSeeIn('.admin-menu', adminLine('admin/calendar.page.add_booking'));
    Look::shot($page, 'menu-calendar-add');
    $page->click('.admin-calendar-add__closure')
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

it('switches Colour by to Status from the filter card, the booking\'s hue changing from its member\'s to its status\'s and the legend listing the statuses', function () {
    $cora = calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00');
    $chip = '.admin-calendar-chip[data-uuid="'.$cora.'"]';
    $hue = fn (): string => "document.querySelector('{$chip}').style.getPropertyValue('border-left-color')";

    // Week is a row per member here, so the booking is a chip, its edge in its hue.
    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))->waitForText('Cora Cut');
    $before = $page->assertValue('.admin-calendar-colour-by', 'staff')->script($hue());

    expect($before)->toBeString()->not->toBe('')->not->toContain('var(');

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

/** A day of a member's row on Week, by the member. */
function calendarCell(int $staffId, string $day): string
{
    return '.admin-calendar-week__cell[data-row="member-'.$staffId.'"][data-date="'.$day.'"]';
}

/** A Day column's selector by its member. */
function calendarColumn(int $staffId): string
{
    return '.admin-calendar-column[data-column="member-'.$staffId.'"]';
}

/** The Playwright page under the plugin's page, for the mouse's own press at a point. */
function calendarPlaywright(AwaitableWebpage|Webpage $page): Page
{
    return $page instanceof AwaitableWebpage ? $page->page() : throw new LogicException('The walk hands the awaitable page.');
}

/**
 * Presses the mouse on the element given, at a point counted from its top left corner or at its
 * middle, and lets go there. The press is made wherever it lands, with no wait for the element
 * to be the one under the pointer, so a press the page keeps from the element is made all the
 * same and the walk reads what came of it.
 *
 * @param  array{x: int, y: int}|null  $at
 */
function calendarPress(AwaitableWebpage|Webpage $page, string $selector, ?array $at = null): void
{
    calendarPlaywright($page)->locator($selector)->click(['force' => true] + ($at === null ? [] : ['position' => $at]));
}

/**
 * Scrolls the page until the point given of the element lies twenty-four pixels above the
 * window's bottom edge, too little for a menu's list to hang under it; answers the room left
 * under the point, to read that the page did scroll there.
 *
 * @param  array{x: int, y: int}  $at
 */
function calendarLowInWindow(string $selector, array $at): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const top = () => document.querySelector({$selector}).getBoundingClientRect().top + {$at['y']};
            window.scrollBy(0, top() - (window.innerHeight - 24));
            return Math.round(window.innerHeight - top()) === 24;
        })()
        JS;
}

/**
 * Where the open date menu's list lies about the point pressed, the point counted from the top
 * left corner of the element given: 'under' or 'over' where the list starts at the point
 * sideways and its near edge lies within eight pixels of it, the point itself outside the list;
 * else what was found, after two seconds of looking, which is a failure worth reading. The
 * library draws the list and puts it in its place a moment later, so the read is made again
 * until the list lies there.
 *
 * @param  array{x: int, y: int}  $at
 */
function calendarListAbout(string $selector, array $at): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        new Promise((resolve) => {
            const started = Date.now();
            const read = () => {
                const box = document.querySelector({$selector})?.getBoundingClientRect();
                const list = document.querySelector('.admin-menu')?.parentElement?.getBoundingClientRect();
                if (box === undefined || list === undefined) {
                    return 'no list';
                }
                const x = box.left + {$at['x']};
                const y = box.top + {$at['y']};
                if (Math.abs(list.left - x) > 2) {
                    return 'aside by ' + Math.round(list.left - x);
                }
                if (list.top >= y && list.top - y <= 8) {
                    return 'under';
                }
                if (list.bottom <= y && y - list.bottom <= 8) {
                    return 'over';
                }
                return 'apart, from ' + Math.round(list.top - y) + ' to ' + Math.round(list.bottom - y);
            };
            const check = () => {
                const found = read();
                if (found === 'under' || found === 'over' || Date.now() - started > 2000) {
                    resolve(found);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS;
}

/**
 * How the page lies about the element given, as one line to read before and after a step that
 * must move none of it: how far the page is scrolled, where the element's corner is in the
 * window, and whether the body scrolls and keeps its edge.
 */
function calendarLieOf(string $selector): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const box = document.querySelector({$selector}).getBoundingClientRect();
            const body = getComputedStyle(document.body);
            return [window.scrollX, window.scrollY, box.left, box.top, body.overflowY, body.marginRight, body.paddingRight].join(' ');
        })()
        JS;
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

it('adds a closure of three days from a Day column\'s Closure here, its date prefilled and Until picked, the same hours on each, and draws it on all three days', function () {
    $days = calendarNextWednesday();
    $wednesday = calendarDay($days);
    $friday = calendarDay($days + 2);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $closure = calendarColumn($elena).' .admin-calendar-closure';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$wednesday))
        ->waitForText('Elena Papadaki');

    $page->click(calendarColumn($elena).' .admin-calendar-head__menu')
        ->click('.admin-calendar-column__close')
        ->waitForText(adminLine('admin/calendar.closure.title'))
        ->assertValue('#closure-who', (string) $elena)
        ->assertSeeIn('.admin-closure', adminLine('admin/calendar.closure.until'))
        ->assertSeeIn('.admin-closure', adminLine('admin/calendar.closure.until_help'))
        ->click('.admin-closure__until');

    // Until's picker opens on the tenant's month; Friday may sit in the next one.
    if (substr($friday, 0, 7) !== substr(calendarToday(), 0, 7)) {
        $page->click(sprintf('.admin-date-field__popover button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click(sprintf('button[data-date="%s"]', $friday))
        ->click('#closure-all-day')
        ->type('#closure-reason', 'Holiday')
        ->click('.admin-closure__add')
        ->waitForText(adminLine(BlackoutController::ADDED))
        ->assertMissing('.admin-closure')
        ->assertSeeIn($closure, 'Holiday');

    foreach ([calendarDay($days + 1), $friday] as $date) {
        $page->click('.admin-calendar-next')
            ->assertPresent(calendarColumn($elena).' .fc-timegrid-col[data-date="'.$date.'"]')
            ->assertSeeIn($closure, 'Holiday');
    }

    $page->assertNoJavaScriptErrors();

    $rows = DB::table('tenant_luna.blackouts')->where('staff_id', $elena)->where('reason', 'Holiday')->orderBy('date');

    expect((clone $rows)->pluck('date')->all())->toBe([$wednesday, calendarDay($days + 1), $friday])
        ->and((clone $rows)->pluck('start_time')->all())->toBe(['09:00:00', '09:00:00', '09:00:00'])
        ->and((clone $rows)->pluck('end_time')->all())->toBe(['17:00:00', '17:00:00', '17:00:00']);
});
