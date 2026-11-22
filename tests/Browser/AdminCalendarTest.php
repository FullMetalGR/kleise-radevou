<?php

use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\BookingActionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Site\SiteLanguage;
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
 * the three days. A booking cancelled, missed or expired is struck through on its block and its
 * chip, a request and one awaiting payment are outlined in their own colours, and every booking's
 * words hold their contrast on its fill in three themes, light and dark; the now line is drawn in
 * its own red with its pill; and from the desktop width the time grid's box is bounded by the
 * window, the heads staying at its top as the slots scroll under them. On a phone the filters
 * fold behind their button; Week is its grid of people inside the screen, the chips cut to their
 * start times and the plus and how many more opening Day on the row's member; Week of one member
 * is the time grid of seven columns; Month draws two chips a day and its day's number opens Day.
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
 * negative, an hour long or the minutes given: a request on an open approval window, an
 * approved booking awaiting its payment under a live payment hold, or a confirmed booking.
 */
function calendarBooking(string $customer, string $status, string $member, string $time, string $service = 'Haircut', int $days = 0, int $minutes = 60): string
{
    $uuid = (string) Str::uuid();
    $athens = new DateTimeZone('Europe/Athens');
    $day = calendarDay($days);
    $start = new DateTimeImmutable($day.' '.$time, $athens);
    $end = $start->add(new DateInterval('PT'.$minutes.'M'));
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
    // and the week becomes her time grid, its bookings as blocks, a badge's word in its block.
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
 * A script answering whether the open Add menu wraps its closing line rather than stretching to
 * it: no wider than 288 pixels, nor than the window, and the line on more than one row.
 */
const CALENDAR_ADD_MENU_HOLDS = "(() => { const menu = document.querySelector('.admin-menu[role=\"menu\"]'); const note = menu?.querySelector('.admin-menu__note'); return menu !== null && note !== null && menu.getBoundingClientRect().width <= Math.min(288.5, document.documentElement.clientWidth) && note.getBoundingClientRect().height > parseFloat(getComputedStyle(note).lineHeight) * 1.5; })()";

/** A script answering how many columns the filter card's grid draws at the window's width. */
const CALENDAR_FILTER_COLUMNS = "document.fonts.ready.then(() => getComputedStyle(document.querySelector('.admin-calendar-filters')).gridTemplateColumns.split(' ').filter(Boolean).length)";

/** A script answering whether the page stays inside the window's width, with no sideways scroll. */
const CALENDAR_NO_SIDEWAYS = 'document.fonts.ready.then(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)';

/**
 * A script answering whether the filters' button stands on the date title's line, after the
 * title and inside the toolbar.
 */
const CALENDAR_TOGGLE_ON_TITLE_LINE = "document.fonts.ready.then(() => { const bar = document.querySelector('.admin-calendar-toolbar')?.getBoundingClientRect(); const title = document.querySelector('.admin-calendar-title')?.getBoundingClientRect(); const toggle = document.querySelector('.admin-filters-toggle')?.getBoundingClientRect(); return bar !== undefined && title !== undefined && toggle !== undefined && toggle.left >= title.right - 0.5 && toggle.right <= bar.right + 0.5 && toggle.top < title.bottom && toggle.bottom > title.top && toggle.height >= 44; })";

/**
 * A script answering, of the legend's badge for a booking awaiting payment, the lines its word
 * takes, whether it ends inside the legend and whether the page stays inside the window, a space
 * between each; "not on the page" where no such badge is.
 */
const CALENDAR_PAYMENT_BADGE_ONE_LINE = "document.fonts.ready.then(() => { const badge = document.querySelector('.admin-calendar-legend__badge[data-badge=\"awaiting_payment\"] .admin-calendar-badge'); if (badge === null) { return 'not on the page'; } const words = document.createRange(); words.selectNodeContents(badge); const lines = new Set([...words.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top))).size; const inside = badge.getBoundingClientRect().right <= badge.closest('.admin-calendar-legend').getBoundingClientRect().right + 0.5; return [lines, inside, document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1].join(' '); })";

/**
 * A script answering an empty string where the toolbar's six parts stand on one line in their
 * order, the view switch, Today, Previous, Next, the date title and Add, with Add at the row's
 * far side; otherwise what is out of place.
 */
const CALENDAR_TOOLBAR_ONE_LINE = "document.fonts.ready.then(() => { const bar = document.querySelector('.admin-calendar-toolbar'); const boxes = ['.admin-calendar-view', '.admin-calendar-today', '.admin-calendar-previous', '.admin-calendar-next', '.admin-calendar-title', '.admin-calendar-add'].map((hook) => bar?.querySelector(hook)?.getBoundingClientRect()); if (!bar || boxes.some((box) => !box)) { return 'not on the page'; } const row = bar.getBoundingClientRect(); const middle = (box) => box.top + box.height / 2; const found = []; if (boxes.some((box) => Math.abs(middle(box) - middle(boxes[0])) > 1)) { found.push('not on one line'); } if (boxes.some((box, at) => at > 0 && box.left < boxes[at - 1].right - 0.5)) { found.push('out of order'); } if (Math.abs(boxes[5].right - row.right) > 1) { found.push('Add ' + (row.right - boxes[5].right).toFixed(1) + 'px short of the far side'); } return found.join(', '); })";

/**
 * A script answering an empty string where Add closes the toolbar at its far side on a line of its
 * own, none of the other five parts beside it; otherwise how far short of that side it stands or
 * that it shares its line, with the width read.
 */
const CALENDAR_ADD_AT_FAR_SIDE = "document.fonts.ready.then(() => { const bar = document.querySelector('.admin-calendar-toolbar'); const add = bar?.querySelector('.admin-calendar-add'); if (!bar || !add) { return 'not on the page'; } const box = add.getBoundingClientRect(); const short = bar.getBoundingClientRect().right - box.right; const beside = ['.admin-calendar-view', '.admin-calendar-today', '.admin-calendar-previous', '.admin-calendar-next', '.admin-calendar-title'].map((hook) => bar.querySelector(hook)?.getBoundingClientRect()).filter((part) => part !== undefined && part.bottom > box.top + 0.5 && part.top < box.bottom - 0.5); const found = [...(Math.abs(short) <= 1 ? [] : ['Add ' + short.toFixed(1) + 'px short of the far side']), ...(beside.length === 0 ? [] : ['Add sharing its line'])]; return found.length === 0 ? '' : 'at ' + document.documentElement.clientWidth + 'px: ' + found.join(', '); })";

/**
 * A script answering an empty string where the toolbar reads as a phone's: the view switch across
 * the row; under it Today, Previous and Next in order with Add at the far end, round and named by
 * the line given; the date title under them across the row; and the page not scrolling sideways.
 * Otherwise what is out of place, with the width read.
 */
function calendarPhoneToolbar(string $add): string
{
    $name = json_encode($add, JSON_THROW_ON_ERROR);

    return "document.fonts.ready.then(() => { const bar = document.querySelector('.admin-calendar-toolbar'); const [view, today, previous, next, title, add] = ['.admin-calendar-view', '.admin-calendar-today', '.admin-calendar-previous', '.admin-calendar-next', '.admin-calendar-title', '.admin-calendar-add'].map((hook) => bar?.querySelector(hook)); if (!bar || !view || !today || !previous || !next || !title || !add) { return 'not on the page'; } const row = bar.getBoundingClientRect(); const box = (el) => el.getBoundingClientRect(); const line = [today, previous, next, add].map(box); const middle = (each) => each.top + each.height / 2; const found = []; if (Math.abs(box(view).width - row.width) > 1 || Math.abs(box(view).left - row.left) > 1) { found.push('the view switch ' + box(view).width.toFixed(1) + 'px of ' + row.width.toFixed(1)); } if (line.some((each) => each.top < box(view).bottom - 0.5)) { found.push('the moves beside the view switch'); } if (line.some((each) => Math.abs(middle(each) - middle(line[0])) > 1)) { found.push('the moves off one line'); } if (line.some((each, at) => at > 0 && each.left < line[at - 1].right - 0.5)) { found.push('the moves out of order'); } if (Math.abs(line[3].right - row.right) > 1) { found.push('Add ' + (row.right - line[3].right).toFixed(1) + 'px short of the far end'); } if (!add.classList.contains('admin-button--icon-only') || Math.abs(line[3].width - line[3].height) > 0.5 || add.textContent.trim() !== {$name}) { found.push('Add not round with its name'); } if (box(title).top < Math.max(...line.map((each) => each.bottom)) - 0.5 || box(title).width > row.width + 0.5) { found.push('the title not under the moves'); } if (document.documentElement.scrollWidth > document.documentElement.clientWidth + 1) { found.push('the page ' + document.documentElement.scrollWidth + 'px wide in ' + document.documentElement.clientWidth); } return found.length === 0 ? '' : 'at ' + document.documentElement.clientWidth + 'px: ' + found.join(', '); })";
}

/**
 * A script answering an empty string where every filter holds its longest value in the screen's
 * own words: Who each of its presets given, Status and Colour by each of their options, Service its
 * All services, the tenant's service names being the tenant's own; otherwise each that cuts one,
 * the value's width and the room for it. A select keeps its chevron's room at its end, Who its
 * chevron and the gap before it.
 *
 * @param  list<string>  $who
 */
function calendarFiltersHold(array $who): string
{
    $words = json_encode($who, JSON_THROW_ON_ERROR);

    return "document.fonts.ready.then(() => { const pen = document.createElement('canvas').getContext('2d'); const cut = (el, values, spare) => { const style = getComputedStyle(el); pen.font = style.fontStyle + ' ' + style.fontWeight + ' ' + style.fontSize + ' ' + style.fontFamily; const room = el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight) - spare; return values.map((value) => [value, pen.measureText(value).width]).filter(([, width]) => width > room + 0.5).map(([value, width]) => value + ' ' + width.toFixed(1) + 'px in ' + room.toFixed(1)); }; const options = (hook, first) => [...document.querySelectorAll(hook + ' option')].slice(0, first ? 1 : undefined).map((option) => option.textContent); const who = document.querySelector('.admin-calendar-who'); const found = [...(who ? cut(who, {$words}, 24) : ['no Who']), ...cut(document.querySelector('.admin-calendar-status'), options('.admin-calendar-status', false), 0), ...cut(document.querySelector('.admin-calendar-service'), options('.admin-calendar-service', true), 0), ...cut(document.querySelector('.admin-calendar-colour-by'), options('.admin-calendar-colour-by', false), 0)]; return found.length === 0 ? '' : 'at ' + document.documentElement.clientWidth + 'px: ' + found.join('; '); })";
}

it('draws the toolbar card with the view switch, Today, Previous, Next, the date title and Add on one line over the filters four to a row, two on a small laptop and one on a phone, and opens and closes Who and Add by the pointer and by the keyboard in Day, Week and Month', function () {
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00');

    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))->waitForText('Cora Cut');
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1440, 900)
        ->assertPresent('.admin-calendar-controls .admin-calendar-toolbar')
        ->assertNotPresent('.admin-calendar-heading .admin-calendar-add')
        ->assertPresent('.admin-calendar-heading .admin-new-booking.admin-button--primary')
        ->assertScript(CALENDAR_TOOLBAR_ONE_LINE, '')
        ->assertScript(CALENDAR_FILTER_COLUMNS, 4)
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-1440');

    foreach (['day', 'week', 'month'] as $view) {
        $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view='.$view))
            ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.'.$view))
            ->assertScript(CALENDAR_TOOLBAR_ONE_LINE, '')
            ->click('#calendar-who')
            ->assertVisible('.admin-calendar-who__popover');
        Look::shot($page, 'calendar-'.$view.'-who');
        $page->click('.admin-calendar-who__preset[data-who="all"]')
            ->assertMissing('.admin-calendar-who__popover')
            ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.all'))
            ->assertScript(Admin::focusIsOn('#calendar-who'), true)
            ->click('.admin-calendar-add')
            ->assertSeeIn('.admin-menu', adminLine('admin/calendar.page.add_booking'))
            ->assertScript(CALENDAR_ADD_MENU_HOLDS, true);
        Look::shot($page, 'calendar-'.$view.'-add');
        $page->keys('.admin-menu', 'Escape')
            ->assertMissing('.admin-menu')
            ->assertScript(Admin::focusIsOn('.admin-calendar-add'), true);
    }

    // By the keyboard alone: Enter opens Who and picks a preset, which closes it; Enter opens Add,
    // whose first item takes the focus, and Escape closes it, focus back on Add each time.
    $page->keys('#calendar-who', 'Enter')
        ->assertVisible('.admin-calendar-who__popover')
        ->keys('.admin-calendar-who__preset[data-who="scheduled"]', 'Enter')
        ->assertMissing('.admin-calendar-who__popover')
        ->assertSeeIn('#calendar-who', adminLine('admin/calendar.who.scheduled'))
        ->assertScript(Admin::focusIsOn('#calendar-who'), true)
        ->keys('.admin-calendar-add', 'Enter')
        ->assertScript(Admin::focusIsOn('.admin-calendar-add__booking'), true)
        ->keys('.admin-calendar-add__booking', 'Escape')
        ->assertMissing('.admin-menu')
        ->assertScript(Admin::focusIsOn('.admin-calendar-add'), true);

    $page->resize(1000, 900)
        ->assertScript(CALENDAR_FILTER_COLUMNS, 2)
        ->assertNotPresent('.admin-filters-toggle')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-1000-month');

    // On a phone the filters wait folded behind their button on the title's line, the card
    // closed until the button opens it, one field to a row.
    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day'))
        ->resize(390, 844)
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertAttribute('.admin-filters-toggle', 'aria-expanded', 'false')
        ->assertAttribute('.admin-filters-toggle', 'aria-controls', 'calendar-filters')
        ->assertScript("document.getElementById('calendar-filters').hidden", true)
        ->assertScript(calendarPhoneToolbar(adminLine('admin/calendar.page.add')), '')
        ->assertScript(CALENDAR_TOGGLE_ON_TITLE_LINE, true);
    Look::shot($page, 'calendar-390-day-folded');
    $page->click('.admin-filters-toggle')
        ->assertAttribute('.admin-filters-toggle', 'aria-expanded', 'true')
        ->assertVisible('#calendar-who')
        ->assertScript(CALENDAR_FILTER_COLUMNS, 1)
        ->assertScript(calendarPhoneToolbar(adminLine('admin/calendar.page.add')), '');
    Look::shot($page, 'calendar-390-day');
    $page->click('.admin-calendar-add')
        ->assertSeeIn('.admin-menu[role="menu"]', adminLine('admin/calendar.page.add_booking'))
        ->assertScript(CALENDAR_ADD_MENU_HOLDS, true)
        ->keys('.admin-menu[role="menu"]', 'Escape')
        ->assertMissing('.admin-menu[role="menu"]')
        ->assertScript(Admin::focusIsOn('.admin-calendar-add'), true)
        ->resize($size['width'], $size['height']);
});

it('holds each filter\'s longest Greek value four to a row at 1440 pixels, at a laptop\'s 1366 and 1280, through the widths where two to a row turn into four and two to a row at 1000, and at 390 spans the view switch, then Today, Previous, Next and Add round at the far end named in Greek, then the date title, the page never scrolling sideways in Day, Week or Month', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $greek = fn (string $key): string => adminLine('admin/calendar.'.$key, [], SiteLanguage::Greek);
    $holds = calendarFiltersHold([$greek('who.scheduled'), $greek('who.all')]);

    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', $greek('toolbar.views.week'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1440, 900)
        ->assertScript(CALENDAR_FILTER_COLUMNS, 4)
        ->assertScript($holds, '')
        ->assertScript(CALENDAR_TOOLBAR_ONE_LINE, '')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-greek-1440');

    // A laptop's widths beside the open sidebar: every field still holds its longest value,
    // however many of them the row takes there.
    foreach ([1366, 1280] as $width) {
        $page->resize($width, 900)
            ->assertScript($holds, '')
            ->assertScript(CALENDAR_NO_SIDEWAYS, true);
        Look::shot($page, 'calendar-greek-'.$width);
    }

    // Two to a row turn into four somewhere above the laptop's width, and no width on the way
    // draws four fields too narrow for their values.
    foreach (range(1368, 1400, 2) as $width) {
        $page->resize($width, 900)->assertScript($holds, '');
    }

    $page->resize(1000, 900)
        ->assertScript(CALENDAR_FILTER_COLUMNS, 2)
        ->assertScript($holds, '')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-greek-1000');
    $page->resize(390, 844);

    foreach (['day', 'week', 'month'] as $view) {
        $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view='.$view))
            ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', $greek('toolbar.views.'.$view))
            ->assertSeeIn('.admin-filters-toggle', $greek('filters.label'))
            ->assertScript(CALENDAR_TOGGLE_ON_TITLE_LINE, true)
            ->click('.admin-filters-toggle')
            ->assertScript(CALENDAR_FILTER_COLUMNS, 1)
            ->assertScript($holds, '')
            ->assertScript(calendarPhoneToolbar($greek('page.add')), '');

        // Week is its grid of people, every chip and line of more inside its day, no word under
        // 12 pixels.
        if ($view === 'week') {
            $page->assertPresent('.admin-calendar-week')
                ->assertScript(calendarPhoneFits('.admin-calendar-week'), '')
                ->assertScript(CALENDAR_SMALL_TYPE, '');
        }

        Look::shot($page, 'calendar-greek-390-'.$view);
    }

    // The legend under Day's grid keeps the badge of a booking awaiting payment on one line, its
    // row of entries wrapping instead, inside the card.
    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day'))
        ->assertSeeIn('.admin-calendar-legend__badge[data-badge="awaiting_payment"] .admin-calendar-badge', adminLine('admin/shell.kit.statuses.awaiting_payment', [], SiteLanguage::Greek))
        ->assertScript(CALENDAR_PAYMENT_BADGE_ONE_LINE, '1 true true');

    $page->resize($size['width'], $size['height']);
});

it('keeps Add at the toolbar\'s far side where a row too short for all six parts leaves it a line of its own', function (SiteLanguage $language, int $width) {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::onCalendar($this, 'luna', Admin::owner('luna'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.week', [], $language));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize($width, 900)
        ->assertScript(CALENDAR_ADD_AT_FAR_SIDE, '');
    Look::shot($page, 'calendar-add-'.$language->value.'-'.$width);
    $page->resize($size['width'], $size['height']);
})->with(['in English' => [SiteLanguage::English, 1200], 'in Greek' => [SiteLanguage::Greek, 1280]]);

/**
 * A script resolving true once an element matching the selector given is on the page; false
 * after five seconds.
 */
function calendarDrawn(string $selector): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);

    return "new Promise((resolve) => { const started = Date.now(); const look = () => (document.querySelector({$selector}) !== null ? resolve(true) : Date.now() - started > 5000 ? resolve(false) : setTimeout(look, 50)); look(); })";
}

/**
 * A script answering the start times drawn on the chips inside the element given, in order, a
 * space between.
 */
function calendarTimes(string $selector): string
{
    return sprintf("[...document.querySelectorAll(%s)].map((time) => time.textContent).join(' ')", json_encode($selector.' .admin-calendar-chip__time', JSON_THROW_ON_ERROR));
}

/**
 * A script answering an empty string where every chip, closure line and line of more inside the
 * element given sits inside its day, with the words it draws inside its own box, and the page
 * stays inside the window; otherwise each that does not, by its hook and its widths.
 */
function calendarPhoneFits(string $selector): string
{
    $root = json_encode($selector, JSON_THROW_ON_ERROR);

    return <<<JS
        document.fonts.ready.then(() => {
            const found = [];
            const root = document.querySelector({$root});
            if (root === null) {
                return 'not on the page';
            }
            for (const piece of root.querySelectorAll('.admin-calendar-chip, .admin-calendar-week__closure, .admin-calendar-more')) {
                const hook = piece.className.split(' ')[0];
                const box = piece.getBoundingClientRect();
                const day = piece.closest('.admin-calendar-week__cell, .admin-calendar-month__day').getBoundingClientRect();
                if (box.left < day.left - 0.5 || box.right > day.right + 0.5) {
                    found.push(hook + ' ' + box.width.toFixed(1) + 'px in a day of ' + day.width.toFixed(1));
                }
                const style = getComputedStyle(piece);
                const end = box.right - parseFloat(style.paddingRight) - parseFloat(style.borderRightWidth);
                for (const words of piece.querySelectorAll(':scope > [aria-hidden="true"]')) {
                    const drawn = words.getBoundingClientRect();
                    if (drawn.width > 0 && drawn.right > end + 0.5) {
                        found.push(hook + ' cuts ' + JSON.stringify(words.textContent) + ' by ' + (drawn.right - end).toFixed(1) + 'px');
                    }
                }
            }
            if (document.documentElement.scrollWidth > document.documentElement.clientWidth + 1) {
                found.push('the page ' + document.documentElement.scrollWidth + 'px wide in ' + document.documentElement.clientWidth);
            }
            return [...new Set(found)].join(', ');
        })
        JS;
}

/**
 * A script answering, of the one time grid, the width of its axis's labels in whole pixels, the
 * axis's rule after them aside, how many day columns stand beside it, whether they are of one
 * width, whether the grid ends inside the window and whether every day's head keeps its words
 * inside its column, a space between each.
 */
const CALENDAR_ONE_GRID_COLUMNS = <<<'JS'
    document.fonts.ready.then(() => {
        const grid = document.querySelector('.fc-timeGridWeek-view')?.closest('.admin-calendar-grid');
        const axis = grid?.querySelector('.fc-timegrid-slot-label-cushion');
        if (!grid || !axis) {
            return 'not on the page';
        }
        const days = [...grid.querySelectorAll('.fc-col-header-cell')].map((head) => head.getBoundingClientRect().width);
        const even = Math.max(...days) - Math.min(...days) <= 1;
        const inside = grid.getBoundingClientRect().right <= document.documentElement.clientWidth + 0.5;
        const heads = [...grid.querySelectorAll('.fc-col-header-cell-cushion')].every((words) => words.scrollWidth <= words.clientWidth + 0.5 && words.getBoundingClientRect().right <= words.closest('.fc-col-header-cell').getBoundingClientRect().right + 0.5);
        return [Math.round(axis.getBoundingClientRect().width), days.length, even, inside, heads].join(' ');
    })
    JS;

/**
 * A script answering, of the one time grid's day heads once the fonts are in, how each is drawn,
 * each different answer once, a comma between: "full" where the weekday and the whole date stand
 * on one line, "short" where the date is not drawn and the day's number stands under the weekday,
 * drawn from the attribute holding the number of that head's date, "spilt" where the head's words
 * run out of its column or the weekday breaks, and "neither" otherwise.
 */
const CALENDAR_HEAD_FORMS = <<<'JS'
    document.fonts.ready.then(() => {
        const cells = [...document.querySelectorAll('.admin-calendar-grid .fc-col-header-cell')];
        if (cells.length === 0) {
            return 'not on the page';
        }
        const box = (part) => part.getBoundingClientRect();
        const drawn = (part) => part.getClientRects().length > 0;
        const lines = (part) => part.getClientRects().length;
        const middle = (part) => box(part).top + box(part).height / 2;
        const form = (cell) => {
            const cushion = cell.querySelector('.fc-col-header-cell-cushion');
            const words = cell.querySelector('.admin-calendar-grid__head > span');
            if (!cushion || !words || words.children.length !== 3) {
                return 'neither';
            }
            const [weekday, date, number] = words.children;
            const content = getComputedStyle(number, '::after').content;
            const figure = number.dataset.day === String(Number(cell.dataset.date.slice(8))) && !['none', 'normal', '""'].includes(content);
            if (cushion.scrollWidth > cushion.clientWidth + 0.5 || box(cushion).right > box(cell).right + 0.5 || lines(weekday) !== 1) {
                return 'spilt';
            }
            if (drawn(date) && !drawn(number) && lines(date) === 1 && Math.abs(middle(date) - middle(weekday)) < 2) {
                return 'full';
            }
            if (!drawn(date) && drawn(number) && box(number).top >= box(weekday).bottom - 0.5 && figure) {
                return 'short';
            }
            return 'neither';
        };
        return [...new Set(cells.map(form))].join(', ');
    })
    JS;

/**
 * A script answering true once the time grid's layout has settled: the boxes of its frame, its
 * heads and its tables read the same for 300 milliseconds running, longer than the library waits
 * after a window's resize before it lays the grid out again; false where they still change after
 * five seconds.
 */
const CALENDAR_SETTLED = <<<'JS'
    new Promise((resolve) => {
        const parts = () => [...document.querySelectorAll('.admin-calendar-grid, .admin-calendar-grid .fc-col-header-cell, .admin-calendar-grid .fc table')];
        const read = () => parts().map((part) => {
            const box = part.getBoundingClientRect();
            return [box.left, box.top, box.width, box.height].map((value) => value.toFixed(1)).join(' ');
        }).join('|');
        const begun = performance.now();
        let last = read();
        let since = begun;
        const look = () => {
            const now = performance.now();
            const next = read();
            if (next !== last) {
                last = next;
                since = now;
            }
            if (now - since >= 300) {
                resolve(true);
            } else if (now - begun >= 5000) {
                resolve(false);
            } else {
                requestAnimationFrame(look);
            }
        };
        requestAnimationFrame(look);
    })
    JS;

/**
 * A script answering an empty string where every day column of the one time grid is within a
 * pixel of the width given; otherwise each width that is not, to a tenth of a pixel.
 */
function calendarOneGridDays(float $width): string
{
    return sprintf(<<<'JS'
        document.fonts.ready.then(() => {
            const heads = document.querySelector('.fc-timeGridWeek-view')?.closest('.admin-calendar-grid')?.querySelectorAll('.fc-col-header-cell');
            if (!heads || heads.length === 0) {
                return 'not on the page';
            }
            return [...heads].map((head) => head.getBoundingClientRect().width).filter((width) => Math.abs(width - %F) > 1).map((width) => width.toFixed(1) + 'px').join(', ');
        })
        JS, $width);
}

it('draws Week on a phone as its grid of people inside the screen, a member\'s head the avatar alone with the name read, a day\'s first six bookings cut to their start times, its closure line to its start, and the plus and how many more opening Day on that member; Week of one member as a time grid of seven columns beside a 48 pixel axis; Month two chips a day and the day\'s number a button opening Day; a chip opening its booking, no chip dragged and no text under 12 pixels', function () {
    $days = calendarNextWednesday();
    $wednesday = calendarDay($days);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $chip = fn (string $uuid): string => '.admin-calendar-chip[data-uuid="'.$uuid.'"]';
    $mine = calendarCell($elena, $wednesday);
    $uuids = [];

    foreach (['09:00' => 'Ada Early', '10:00' => 'Cora Cut', '11:00' => 'Dan Mid', '12:00' => 'Eve Noon', '14:00' => 'Fay After', '15:00' => 'Gil Late', '16:00' => 'Hal Last'] as $time => $customer) {
        $uuids[$customer] = calendarBooking($customer, 'confirmed', 'Elena Papadaki', $time, days: $days);
    }

    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => $wednesday, 'start_time' => '17:00', 'end_time' => '18:00', 'reason' => 'Training']);
    $dayTitle = adminLine('admin/calendar.toolbar.day_title', ['weekday' => 'Wednesday', 'date' => (new DateTimeImmutable($wednesday, new DateTimeZone('Europe/Athens')))->format('d/m/Y')]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))
        ->assertVisible(Admin::HOME)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$wednesday))
        ->assertScript(calendarDrawn($mine.' .admin-calendar-chip'), true);

    // The grid of people: a member's head is the avatar, the name kept for a screen reader; a day
    // lists its first six bookings at their start times, then its closure at its start and the
    // plus and how many more, all inside the day and the page.
    $page->assertCount('.admin-calendar-week__person', 2)
        ->assertScript("document.querySelector('.admin-calendar-week__person[data-row=\"member-{$elena}\"] .sr-only').textContent", 'Elena Papadaki')
        ->assertCount($mine.' .admin-calendar-chip--short', 6)
        ->assertNotPresent('.admin-calendar-chip__customer')
        ->assertScript(calendarTimes($mine), '09:00 10:00 11:00 12:00 14:00 15:00')
        ->assertSeeIn($mine.' .admin-calendar-week__closure span:nth-child(2)', '17:00')
        ->assertSeeIn($mine.' .admin-calendar-more [aria-hidden="true"]', adminChoice('admin/calendar.cells.more_short', 1))
        ->assertScript(calendarTimes(calendarCell($nikos, $wednesday)), '11:00')
        ->assertScript(calendarPhoneFits('.admin-calendar-week'), '')
        ->assertScript(CALENDAR_SMALL_TYPE, '')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-390-week');

    // A chip opens its booking; a pull on one carries it nowhere.
    $page->click($mine.' '.$chip($uuids['Cora Cut']))
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-modal__title', 'Cora Cut')
        ->click('.admin-booking-close')
        ->assertMissing('.admin-booking-dialog')
        ->assertScript(Admin::focusIsOn($mine.' '.$chip($uuids['Cora Cut'])), true);
    calendarPlaywright($page)->locator($mine.' '.$chip($uuids['Ada Early']))->dragTo(calendarPlaywright($page)->locator(calendarCell($nikos, calendarDay($days + 1))), ['sourcePosition' => ['x' => 8, 'y' => 6], 'targetPosition' => ['x' => 12, 'y' => 8], 'steps' => 12, 'force' => true]);
    $page->assertNotPresent('.admin-move-confirm')
        ->assertMissing('.admin-booking-dialog')
        ->assertCount($mine.' .admin-calendar-chip', 6);

    // The plus and how many more opens Day on that date narrowed to the row's member, which the
    // filters' button counts as one filter set.
    $page->click($mine.' .admin-calendar-more')
        ->waitForText(calendarBlock('Hal Last', 'Haircut'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $dayTitle)
        ->assertDontSee(calendarBlock('Bo South', 'Beard trim'))
        ->assertScript("document.getElementById('calendar-who').textContent.includes('Elena Papadaki')", true)
        ->assertSeeIn('.admin-filters-toggle__count', '1');

    // Week of one member is the time grid: the axis's labels 48 pixels wide and seven columns of
    // one width inside the phone, each day's head inside its column.
    $page->click('.admin-calendar-view button:nth-child(2)')
        ->assertScript(calendarDrawn('.fc-timeGridWeek-view .fc-col-header-cell'), true)
        ->assertNotPresent('.admin-calendar-week')
        ->assertScript(CALENDAR_ONE_GRID_COLUMNS, '48 7 true true true')
        ->assertScript(CALENDAR_HEAD_FORMS, 'short')
        // A day column is a seventh of what the axis leaves of the grid: the grid 356 pixels
        // wide, the window less two 16 pixel gutters and the card's two 1 pixel edges, and the
        // axis 49, its 48 pixel labels and their 1 pixel rule.
        ->assertScript(calendarOneGridDays((356 - 49) / 7), '')
        ->assertScript(CALENDAR_SMALL_TYPE, '')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-390-week-one');

    // Month lists a day's first two bookings at their start times, then the plus and how many
    // more; the day's number is a button the height of a touch opening Day on the date.
    $month = '.admin-calendar-month__day[data-date="'.$wednesday.'"]';
    $page->click('.admin-calendar-view button:nth-child(3)')
        ->assertScript(calendarDrawn($month.' .admin-calendar-chip--short'), true)
        ->assertScript(calendarTimes($month), '09:00 10:00')
        ->assertSeeIn($month.' .admin-calendar-more [aria-hidden="true"]', adminChoice('admin/calendar.cells.more_short', 5))
        ->assertNotPresent('.admin-calendar-month__count')
        ->assertScript(calendarNumberReach($month), 'true true true')
        ->assertScript(calendarPhoneFits('.admin-calendar-month'), '')
        ->assertScript(CALENDAR_SMALL_TYPE, '')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true);
    Look::shot($page, 'calendar-390-month');
    $page->click($month.' .admin-calendar-month__open')
        ->waitForText(calendarBlock('Ada Early', 'Haircut'))
        ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.day'))
        ->assertSeeIn('.admin-calendar-title', $dayTitle)
        ->assertNoJavaScriptErrors();
});

/**
 * A script answering, of the day's number in the Month day given, whether its reach across it is
 * a touch's 44 pixels, whether its reach down it is, and whether its drawn box is narrower than
 * the day, a space between each. A reach is read from what a point hits, from the middle of the
 * drawn box out to each side in steps of a quarter pixel, until a point no longer lands on it,
 * twice: once on what a press there meets, the topmost element, which must reach at least 44
 * pixels, and once on everything under the point, the next day's cell above the layer or not,
 * which must reach less than 46. The browser's own hit area runs up to a pixel past the layer
 * drawn on one side or the other, so the 44 pixel layer reads under 46, and a layer of 46 pixels
 * or more reads over 46. The day is first scrolled to the window's middle, clear of the bottom
 * bar.
 */
function calendarNumberReach(string $cell): string
{
    $cell = json_encode($cell, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const day = document.querySelector({$cell});
            const number = day?.querySelector('.admin-calendar-month__open');
            if (!day || !number) {
                return 'not on the page';
            }
            day.scrollIntoView({ block: 'center' });
            const box = number.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            const lands = (left, top, under) => (under ? document.elementsFromPoint(left, top).some((hit) => number.contains(hit)) : number.contains(document.elementFromPoint(left, top)));
            const out = (across, down, under) => {
                let far = 0;
                while (far < 200 && lands(x + across * (far + 0.25), y + down * (far + 0.25), under)) {
                    far += 0.25;
                }
                return far + 0.125;
            };
            const reaches = (across, down) => out(-across, -down, false) + out(across, down, false) >= 44 && out(-across, -down, true) + out(across, down, true) < 46;
            return [reaches(1, 0), reaches(0, 1), box.width < day.getBoundingClientRect().width].join(' ');
        })()
        JS;
}

/**
 * A script answering, of the Month day given, where the strip at the foot of the cell does not
 * open the day's menu: an empty string where every point from one pixel to 21 pixels above the
 * cell's own foot, a pixel apart, four pixels in from either side and at the middle, lands on the
 * day's menu and on no other control in the day; else, for each of the three lines up the strip
 * that fails, how far in it is and the height at which it first fails, a comma between. The
 * strip is drawn 24 pixels tall and the browser's hit area can run up to a pixel past a
 * control's drawn layer, so the 21 pixels read keep two to spare, and a strip a spacing step
 * shorter, 20 pixels, fails. The day is first scrolled to the window's middle, clear of the
 * bottom bar.
 */
function calendarMonthFoot(string $cell): string
{
    $cell = json_encode($cell, JSON_THROW_ON_ERROR);

    return <<<JS
        document.fonts.ready.then(() => {
            const day = document.querySelector({$cell});
            if (day === null) {
                return 'not on the page';
            }
            day.scrollIntoView({ block: 'center' });
            const menu = day.querySelector('.admin-calendar-date');
            const others = [...day.querySelectorAll('button')].filter((control) => control !== menu);
            const box = day.getBoundingClientRect();
            const foot = box.bottom - parseFloat(getComputedStyle(day).borderBottomWidth);
            const found = [];
            for (const x of [box.left + 4, box.left + box.width / 2, box.right - 4]) {
                for (let up = 1; up <= 21; up += 1) {
                    const hit = document.elementFromPoint(x, foot - up);
                    if (hit?.closest('.admin-calendar-date') !== menu || others.some((control) => control.contains(hit))) {
                        found.push((x - box.left).toFixed(1) + 'px in, from ' + up + 'px up');
                        break;
                    }
                }
            }
            return found.join(', ');
        })
        JS;
}

it('draws the legend on a phone under Day and Week of one member alone, never under Month or the grid of people, and leaves each Month day in Greek, busy or empty, a strip at its foot that opens the day\'s menu, the day\'s number a button the size of its pill', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $days = calendarNextWednesday();
    $wednesday = calendarDay($days);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $mine = calendarCell($elena, $wednesday);

    foreach (['09:00' => 'Ada Early', '10:00' => 'Cora Cut', '11:00' => 'Dan Mid', '12:00' => 'Eve Noon', '14:00' => 'Fay After', '15:00' => 'Gil Late', '16:00' => 'Hal Last'] as $time => $customer) {
        calendarBooking($customer, 'confirmed', 'Elena Papadaki', $time, days: $days);
    }

    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);

    // The grid of people draws its chips cut to their times, so no legend names their marks.
    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))
        ->assertVisible(Admin::HOME)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$wednesday))
        ->assertScript(calendarDrawn($mine.' .admin-calendar-chip'), true)
        ->assertPresent('.admin-calendar-week')
        ->assertNotPresent('.admin-calendar-legend');

    // Day narrowed to Elena from the plus and how many more, then Week of her alone, draw their
    // blocks whole, the legend under each.
    $page->click($mine.' .admin-calendar-more')
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Hal Last', 'service' => 'Haircut'], SiteLanguage::Greek))
        ->assertPresent('.admin-calendar-legend');
    $page->click('.admin-calendar-view button:nth-child(2)')
        ->assertScript(calendarDrawn('.fc-timeGridWeek-view .fc-col-header-cell'), true)
        ->assertNotPresent('.admin-calendar-week')
        ->assertPresent('.admin-calendar-legend');

    // A seventh of a phone is narrower than a head's whole date in Greek, so each of Week's heads
    // is the weekday over the day's number, on the phone and on the narrowest one.
    $size = calendarPlaywright($page)->viewportSize();
    $page->assertScript(CALENDAR_SETTLED, true)
        ->assertScript(CALENDAR_HEAD_FORMS, 'short');
    Look::shot($page, 'calendar-greek-390-week');
    $page->resize(320, $size['height'])
        ->assertScript(CALENDAR_SETTLED, true)
        ->assertScript(CALENDAR_HEAD_FORMS, 'short');
    Look::shot($page, 'calendar-greek-320-week');
    $page->resize($size['width'], $size['height'])
        ->assertScript(CALENDAR_SETTLED, true);

    // Month: a busy day and an empty one each keep the foot of the cell for the day's menu.
    $busy = '.admin-calendar-month__day[data-date="'.$wednesday.'"]';
    $page->click('.admin-calendar-view button:nth-child(3)')
        ->assertScript(calendarDrawn($busy.' .admin-calendar-more'), true)
        ->assertNotPresent('.admin-calendar-legend')
        ->assertScript(calendarMonthFoot($busy), '')
        ->assertScript(calendarMonthFoot('.admin-calendar-month__day:not(:has(.admin-calendar-chip))'), '')
        ->assertScript(calendarNumberReach($busy), 'true true true')
        ->assertScript(CALENDAR_NO_SIDEWAYS, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'calendar-greek-390-month-feet');
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

/**
 * The canvas a contrast script paints on, as statements a script opens with: paint lays colours
 * over each other on one pixel and answers its red, green and blue; unread answers whether the
 * canvas reads no colour from a value; ratio answers the contrast of an ink over a ground laid
 * over the page's own ground, larger luminance over smaller, each with 0.05 added.
 */
const CALENDAR_CANVAS = <<<'JS'
    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    const paint = (colours) => {
        context.clearRect(0, 0, 1, 1);
        for (const colour of colours) {
            context.fillStyle = colour;
            context.fillRect(0, 0, 1, 1);
        }
        return Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3);
    };
    const unread = (colour) => {
        context.fillStyle = '#010203';
        context.fillStyle = colour;
        return context.fillStyle === '#010203';
    };
    const luminance = (rgb) => {
        const [r, g, b] = rgb.map((channel) => {
            const c = channel / 255;
            return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratio = (page, ground, ink) => {
        const [light, deep] = [luminance(paint([page, ground])), luminance(paint([page, ground, ink]))].sort((a, b) => b - a);
        return (light + 0.05) / (deep + 0.05);
    };
    JS;

/**
 * A script dressing the page in the neutral, blue and rose themes, light and dark, and answering
 * every booking's words drawn under 4.5:1 against the fill they sit on, as "<theme> <mode>: <part>
 * '<words>' = <ratio>": a block's title and time and a chip's customer and time, each over its
 * block's or chip's own fill. An empty string where all hold, "nothing measured" where the page
 * shows no block and no chip. The page's theme and mode are put back at the end.
 */
const CALENDAR_FILL_CONTRAST = '(() => {'.CALENDAR_CANVAS.<<<'JS'
        const parts = [
            ...[...document.querySelectorAll('.admin-calendar-block')].flatMap((block) => ['.admin-calendar-block__title', '.admin-calendar-block__time'].map((part) => [block.querySelector(part), block])),
            ...[...document.querySelectorAll('.admin-calendar-chip')].flatMap((chip) => ['.admin-calendar-chip__customer', '.admin-calendar-chip__time'].map((part) => [chip.querySelector(part), chip])),
        ].filter(([text]) => text !== null);
        if (parts.length === 0) {
            return 'nothing measured';
        }
        const body = document.body;
        const root = document.documentElement;
        const was = { theme: body.dataset.theme, dark: root.classList.contains('dark') };
        const failures = [];
        for (const theme of ['neutral', 'blue', 'rose']) {
            for (const dark of [false, true]) {
                body.dataset.theme = theme;
                root.classList.toggle('dark', dark);
                const label = theme + ' ' + (dark ? 'dark' : 'light');
                const page = getComputedStyle(root).backgroundColor;
                for (const [text, ground] of parts) {
                    const fill = getComputedStyle(ground).backgroundColor;
                    const ink = getComputedStyle(text).color;
                    const name = text.className.split(' ')[0] + " '" + text.textContent.trim() + "'";
                    if ([page, fill, ink].some(unread)) {
                        failures.push(label + ': ' + name + ' unread, ' + ink + ' on ' + fill);
                    } else if (ratio(page, fill, ink) < 4.5) {
                        failures.push(label + ': ' + name + ' = ' + ratio(page, fill, ink).toFixed(2));
                    }
                }
            }
        }
        if (was.theme === undefined) {
            delete body.dataset.theme;
        } else {
            body.dataset.theme = was.theme;
        }
        root.classList.toggle('dark', was.dark);
        return failures.join('; ');
    })()
    JS;

/**
 * A script answering the now pill's contrast, its ink over its fill, light and then dark, each
 * rounded to two places; the page's mode is put back at the end.
 */
const CALENDAR_NOW_PILL_RATIOS = '(() => {'.CALENDAR_CANVAS.<<<'JS'
        const pill = document.querySelector('.admin-calendar-now');
        const root = document.documentElement;
        const was = root.classList.contains('dark');
        const ratios = [false, true].map((dark) => {
            root.classList.toggle('dark', dark);
            const style = getComputedStyle(pill);
            return Math.round(ratio(getComputedStyle(root).backgroundColor, style.backgroundColor, style.color) * 100) / 100;
        });
        root.classList.toggle('dark', was);
        return ratios;
    })()
    JS;

/**
 * A script answering the now line and its pill, after looking for them for three seconds: the
 * line's width, whether the line and the pill's fill are the now line's token and the pill's ink
 * the destructive ink, as each resolves in place, and the pill's figures' spacing; or "no now
 * line".
 */
const CALENDAR_NOW_LINE = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const check = () => {
            const line = document.querySelector('.fc-timegrid-now-indicator-line');
            const pill = document.querySelector('.admin-calendar-now');
            if ((line === null || pill === null) && Date.now() - started < 3000) {
                setTimeout(check, 50);
                return;
            }
            if (line === null || pill === null) {
                resolve('no now line');
                return;
            }
            const tone = (token) => {
                const probe = document.createElement('span');
                probe.style.color = 'var(' + token + ')';
                line.appendChild(probe);
                const colour = getComputedStyle(probe).color;
                probe.remove();
                return colour;
            };
            const drawn = getComputedStyle(line);
            const filled = getComputedStyle(pill);
            resolve([drawn.borderTopWidth, drawn.borderTopColor === tone('--now-line'), filled.backgroundColor === tone('--now-line'), filled.color === tone('--destructive-foreground'), filled.fontVariantNumeric].join(' '));
        };
        check();
    })
    JS;

/**
 * A script answering the time grid's box at the window's size, Day's row of columns or the one
 * grid's own box, as "<height> <in view>": its height in whole pixels, and whether all of it lies
 * inside the window once it is scrolled into view.
 */
const CALENDAR_BOX = "(() => { const box = document.querySelector('.admin-calendar-columns__row') ?? document.querySelector('.admin-calendar-grid'); box.scrollIntoView({ block: 'nearest' }); const at = box.getBoundingClientRect(); return Math.round(at.height) + ' ' + (at.top >= -0.5 && at.bottom <= window.innerHeight + 0.5); })()";

/**
 * A script answering whether the one grid's slots run to the foot of their scroller, so the box
 * leaves no empty band under the last hour.
 */
const CALENDAR_NO_BAND = "(() => { const slots = document.querySelector('.admin-calendar-grid .fc-timegrid-slots'); const scroller = slots?.closest('.fc-scroller'); return scroller !== null && scroller !== undefined && slots.getBoundingClientRect().bottom >= scroller.getBoundingClientRect().bottom - 1; })()";

/**
 * A script answering an empty string where the grid's heads stay at the top of its box as the
 * slots scroll under them, by up to 120 pixels and at least 40: Day's row of columns scrolled
 * down, each column's head still at the row's top and the one under its own middle, or the one
 * grid's slots scrolled under its day header, the header still at the box's top. Otherwise what
 * was found.
 */
const CALENDAR_HEAD_STAYS = <<<'JS'
    (() => {
        const row = document.querySelector('.admin-calendar-columns__row');
        const box = row ?? document.querySelector('.admin-calendar-grid');
        const scroller = row ?? [...box.querySelectorAll('.fc-scroller')].find((candidate) => candidate.querySelector('.fc-timegrid-body') !== null);
        const heads = [...box.querySelectorAll(row === null ? '.fc-col-header' : '.admin-calendar-column__head')];
        const lane = scroller?.querySelector('.fc-timegrid-slot-lane');
        if (scroller === undefined || heads.length === 0 || lane === null) {
            return 'not on the page';
        }
        box.scrollIntoView({ block: 'nearest' });
        if (scroller.scrollHeight <= scroller.clientHeight + 1) {
            return 'nothing to scroll, ' + scroller.scrollHeight + ' in ' + scroller.clientHeight;
        }
        scroller.scrollTop = 0;
        const before = lane.getBoundingClientRect().top;
        scroller.scrollTop = 120;
        const scrolled = scroller.scrollTop;
        const moved = before - lane.getBoundingClientRect().top;
        const top = box.getBoundingClientRect().top + (row === null ? box.clientTop : 0);
        const found = [];
        if (scrolled < 40 || Math.abs(moved - scrolled) > 1) {
            found.push('the slots moved ' + moved.toFixed(1) + ' for ' + scrolled + ' scrolled');
        }
        for (const head of heads) {
            const at = head.getBoundingClientRect();
            if (Math.abs(at.top - top) > 1.5) {
                found.push('a head ' + (at.top - top).toFixed(1) + 'px from the top');
            }
            const under = document.elementFromPoint(at.left + at.width / 2, at.top + at.height / 2);
            if (under === null || !head.contains(under)) {
                found.push('a head covered by ' + (under?.className ?? 'nothing'));
            }
        }
        return found.join(', ');
    })()
    JS;

/**
 * A script answering an empty string where the grid opened at its scroll time, looking for two
 * seconds: the box scrolled down to the slot of that time, the slot at the top of the slots seen,
 * under Day's column heads or at the one grid's scroller's top. Otherwise the time, how far the
 * box is scrolled and how far the slot lies from that top.
 */
const CALENDAR_AT_SCROLL_TIME = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const read = () => {
            const grid = document.querySelector('.admin-calendar-grid[data-scroll-time]');
            const row = grid?.querySelector('.admin-calendar-columns__row') ?? null;
            const scroller = row ?? [...(grid?.querySelectorAll('.fc-scroller') ?? [])].find((candidate) => candidate.querySelector('.fc-timegrid-body') !== null);
            const time = grid?.dataset.scrollTime;
            const lane = grid?.querySelector('.fc-timegrid-slot-lane[data-time="' + time + '"]');
            if (scroller === undefined || lane === null || lane === undefined) {
                return 'no slot at ' + time;
            }
            const top = row === null ? scroller.getBoundingClientRect().top : row.querySelector('.admin-calendar-column__head').getBoundingClientRect().bottom;
            const off = lane.getBoundingClientRect().top - top;
            return scroller.scrollTop > 0 && Math.abs(off) <= 1.5 ? '' : time + ' scrolled ' + scroller.scrollTop + ' of ' + (scroller.scrollHeight - scroller.clientHeight) + ', the slot ' + off.toFixed(1) + 'px from the top';
        };
        const check = () => {
            if (read() === '' || Date.now() - started > 2000) {
                resolve(read());
            } else {
                setTimeout(check, 25);
            }
        };
        check();
    })
    JS;

/**
 * The amber a request is outlined in, on its block, its chip and the legend's sample: the
 * attention colour mixed half way to its ink, deep enough a line to hold 3:1 on a light ground.
 */
const CALENDAR_REQUEST_OUTLINE = 'color-mix(in oklab, var(--attention) 50%, var(--attention-ink))';

/**
 * A script dressing the page in the neutral, blue and rose themes, light and dark, and answering
 * every outlined block and chip whose outline holds under 3:1 against a ground it is drawn on, as
 * "<theme> <mode>: <block or chip> '<words>' on <ground> = <ratio>": its own fill, the card, the
 * muted stripe of the hours a member is off and the border's stripe of a closure. An empty string
 * where all hold, "nothing measured" where the page shows no outlined block and no outlined chip.
 * The page's theme and mode are put back at the end.
 */
const CALENDAR_OUTLINE_CONTRAST = '(() => {'.CALENDAR_CANVAS.<<<'JS'
        const outlined = [...document.querySelectorAll('.admin-calendar-block, .admin-calendar-chip')].filter((each) => getComputedStyle(each).outlineStyle !== 'none');
        if (outlined.length === 0) {
            return 'nothing measured';
        }
        const body = document.body;
        const root = document.documentElement;
        const was = { theme: body.dataset.theme, dark: root.classList.contains('dark') };
        const failures = [];
        for (const theme of ['neutral', 'blue', 'rose']) {
            for (const dark of [false, true]) {
                body.dataset.theme = theme;
                root.classList.toggle('dark', dark);
                const label = theme + ' ' + (dark ? 'dark' : 'light');
                const page = getComputedStyle(root).backgroundColor;
                for (const element of outlined) {
                    const style = getComputedStyle(element);
                    const tone = (token) => {
                        const probe = document.createElement('span');
                        probe.style.color = 'var(' + token + ')';
                        element.appendChild(probe);
                        const colour = getComputedStyle(probe).color;
                        probe.remove();
                        return colour;
                    };
                    const name = (element.classList.contains('admin-calendar-chip') ? 'chip' : 'block') + " '" + element.textContent.trim().slice(0, 24) + "'";
                    const grounds = { fill: style.backgroundColor, card: tone('--card'), muted: tone('--muted'), border: tone('--border') };
                    for (const [ground, colour] of Object.entries(grounds)) {
                        if ([page, colour, style.outlineColor].some(unread)) {
                            failures.push(label + ': ' + name + ' on ' + ground + ' unread, ' + style.outlineColor + ' on ' + colour);
                        } else if (ratio(page, colour, style.outlineColor) < 3) {
                            failures.push(label + ': ' + name + ' on ' + ground + ' = ' + ratio(page, colour, style.outlineColor).toFixed(2));
                        }
                    }
                }
            }
        }
        if (was.theme === undefined) {
            delete body.dataset.theme;
        } else {
            body.dataset.theme = was.theme;
        }
        root.classList.toggle('dark', was.dark);
        return failures.join('; ');
    })()
    JS;

/**
 * A script answering every piece of text the calendar draws under 12 pixels, the grid's, a
 * chip's, a badge's and the legend's, once the fonts are in, as "<class> <size>" a kind; an empty
 * string where none is, "nothing measured" where the calendar is not on the page.
 */
const CALENDAR_SMALL_TYPE = <<<'JS'
    document.fonts.ready.then(() => {
        const texts = [...document.querySelectorAll('.admin-calendar *, .admin-calendar-chip, .admin-calendar-chip *, .admin-calendar-badge')].filter((each) => [...each.childNodes].some((node) => node.nodeType === 3 && node.textContent.trim() !== ''));
        if (texts.length === 0) {
            return 'nothing measured';
        }
        const small = texts.filter((each) => parseFloat(getComputedStyle(each).fontSize) < 12).map((each) => (typeof each.className === 'string' ? each.className.split(' ')[0] : each.tagName) + ' ' + getComputedStyle(each).fontSize);
        return [...new Set(small)].join(', ');
    })
    JS;

/**
 * A script answering how the element given is outlined, as "<style> <width> <whether its colour
 * is the colour given>", the colour, a token's var() or a mix of them, resolved inside the
 * element.
 */
function calendarOutlineOf(string $selector, string $colour): string
{
    $selector = json_encode($selector, JSON_THROW_ON_ERROR);
    $token = json_encode($colour, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const element = document.querySelector({$selector});
            const probe = document.createElement('span');
            probe.style.color = {$token};
            element.appendChild(probe);
            const tone = getComputedStyle(probe).color;
            probe.remove();
            const style = getComputedStyle(element);
            return [style.outlineStyle, style.outlineWidth, style.outlineColor === tone].join(' ');
        })()
        JS;
}

/** A script answering the lines drawn through the text of the element given, "none" for none. */
function calendarStrikeOf(string $selector): string
{
    return 'getComputedStyle(document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).')).textDecorationLine';
}

it('strikes through a booking cancelled, rejected, missed or expired on its block and its chip, outlines a request solid in a deepened amber beside its badge and one awaiting payment dashed in the theme colour, each outline holding 3:1 on the grounds it meets and each booking\'s words 4.5:1 on its fill in three themes light and dark, no text under 12 pixels', function () {
    // A Wednesday, which both members work, so Day draws a column each and Week a row each.
    $days = calendarNextWednesday();
    $workday = calendarDay($days);
    $bookings = [
        'confirmed' => calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '09:00', days: $days),
        'cancelled' => calendarBooking('Cal Off', 'cancelled', 'Elena Papadaki', '10:00', days: $days),
        'rejected' => calendarBooking('Rex Refused', 'rejected', 'Nikos Georgiou', '13:00', days: $days),
        'no_show' => calendarBooking('Nell Absent', 'no_show', 'Elena Papadaki', '11:00', days: $days),
        'expired' => calendarBooking('Ezra Lapsed', 'expired', 'Elena Papadaki', '12:00', days: $days),
        'awaiting_approval' => calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '14:00', days: $days),
        'awaiting_payment' => calendarBooking('Una Paid', 'awaiting_payment', 'Elena Papadaki', '15:00', days: $days),
    ];
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '11:00', 'Beard trim', days: $days);
    $block = fn (string $status): string => '.fc-event[data-block-id="'.$bookings[$status].':0"]';
    $chip = fn (string $status): string => '.admin-calendar-chip[data-uuid="'.$bookings[$status].'"]';

    // Day on a day both members work: a column each, the bookings as blocks.
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$workday))
        ->waitForText(calendarBlock('Una Paid', 'Haircut'));

    foreach (['cancelled', 'rejected', 'no_show', 'expired'] as $status) {
        $page->assertScript(calendarStrikeOf($block($status).' .admin-calendar-block__title'), 'line-through');
    }

    $page->assertScript(calendarStrikeOf($block('confirmed').' .admin-calendar-block__title'), 'none')
        ->assertScript("getComputedStyle(document.querySelector('".$block('confirmed')."')).outlineStyle", 'none')
        ->assertScript(calendarOutlineOf($block('awaiting_approval'), CALENDAR_REQUEST_OUTLINE), 'solid 2px true')
        ->assertSeeIn($block('awaiting_approval').' .admin-calendar-badge--approval', adminLine('admin/calendar.badges.pending'))
        ->assertScript(calendarOutlineOf($block('awaiting_payment'), 'var(--primary)'), 'dashed 2px true')
        ->assertSeeIn($block('awaiting_payment').' .admin-calendar-badge--payment', adminLine('admin/shell.kit.statuses.awaiting_payment'))
        ->assertScript(CALENDAR_OUTLINE_CONTRAST, '')
        ->assertScript(CALENDAR_FILL_CONTRAST, '')
        ->assertScript(CALENDAR_SMALL_TYPE, '');

    // Week as a row per member: the same bookings as chips on Elena's day.
    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday))
        ->waitForText('Una Paid');

    foreach (['cancelled', 'rejected', 'no_show', 'expired'] as $status) {
        $page->assertScript(calendarStrikeOf($chip($status).' .admin-calendar-chip__customer'), 'line-through');
    }

    // The legend's sample of each outline is drawn as the chip it stands for.
    $sample = fn (string $badge): string => '.admin-calendar-legend__badge[data-badge="'.$badge.'"] .admin-calendar-legend__swatch';

    $page->assertScript(calendarStrikeOf($chip('confirmed').' .admin-calendar-chip__customer'), 'none')
        ->assertScript(calendarOutlineOf($chip('awaiting_approval'), CALENDAR_REQUEST_OUTLINE), 'solid 2px true')
        ->assertScript(calendarOutlineOf($chip('awaiting_payment'), 'var(--primary)'), 'dashed 2px true')
        ->assertScript(calendarOutlineOf($sample('awaiting_approval'), CALENDAR_REQUEST_OUTLINE), 'solid 2px true')
        ->assertScript(calendarOutlineOf($sample('awaiting_payment'), 'var(--primary)'), 'dashed 2px true')
        ->assertScript(CALENDAR_OUTLINE_CONTRAST, '')
        ->assertScript(CALENDAR_FILL_CONTRAST, '')
        ->assertScript(CALENDAR_SMALL_TYPE, '');

    // Month lists the same bookings as chips, in the same type.
    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=month&date='.$workday))
        ->waitForText('Cora Cut')
        ->assertScript(CALENDAR_SMALL_TYPE, '')
        ->assertNoJavaScriptErrors();
});

it('draws the now line on today two pixels deep in its own red, the time beside it on a pill of that red in the destructive ink with even-width figures, the pill legible light and dark', function () {
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    // Nikos works the whole of today, so the axis holds the time now whatever hour the suite runs.
    DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $nikos, 'date' => calendarToday(), 'windows' => json_encode([['start' => '00:00', 'end' => '24:00']], JSON_THROW_ON_ERROR)]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarToday()))
        ->waitForText('Nikos Georgiou')
        ->assertScript(CALENDAR_NOW_LINE, '2px true true true tabular-nums');

    expect($page->script(CALENDAR_NOW_PILL_RATIOS))->toHaveCount(2)->each->toBeGreaterThanOrEqual(4.5);
});

it('bounds the time grid\'s box by the window from the desktop width, opening at the scroll time with the heads staying at its top as the slots scroll under them, on Day\'s columns and on Week narrowed to one member', function () {
    $days = calendarElenaWorkday();
    $workday = calendarDay($days);
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '13:00', 'Beard trim', days: $days);
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    // A short laptop's window before the grid is drawn, so it opens scrolled to an hour before
    // the first booking, 09:00, an hour down the axis.
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1280, 620)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$workday))
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertAttribute('.admin-calendar-columns', 'data-scroll-time', '09:00:00')
        ->assertScript(CALENDAR_AT_SCROLL_TIME, '')
        ->assertScript(CALENDAR_HEAD_STAYS, '')
        ->assertScript(CALENDAR_BOX, '588 true');
    Look::shot($page, 'calendar-day-columns-1280x620');

    // Twelve hours on the axis, the heads and a rule fit a taller window, so the row holds its own
    // height; 800 pixels wide the bottom bar takes its place at the window's foot, and the box
    // ends above it.
    foreach ([[1440, 900, '821 true'], [800, 900, '787 true']] as [$width, $height, $box]) {
        $page->resize($width, $height)->assertScript(CALENDAR_BOX, $box);
    }

    $page->resize(1280, 620)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday))
        ->waitForText('Cora Cut')
        ->click('#calendar-who')
        ->click('.admin-calendar-who__member[data-staff-id="'.$nikos.'"] input')
        ->click('#calendar-who')
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertNotPresent('.admin-calendar-columns')
        ->assertScript(CALENDAR_AT_SCROLL_TIME, '')
        ->assertScript(CALENDAR_HEAD_STAYS, '');

    // The one grid's box is the window less a rem above and below where its eleven hours and its
    // header run taller, and its own height where they do not, no band left under the last hour;
    // a head is the whole date where its column has the room. Each shot waits for the library to
    // lay the grid out again after the resize.
    foreach ([[1280, 620, '588 true', 'full'], [1440, 900, '694 true', 'full'], [800, 900, '694 true', 'short']] as [$width, $height, $box, $heads]) {
        $page->resize($width, $height)
            ->assertScript(CALENDAR_SETTLED, true)
            ->assertScript(CALENDAR_BOX, $box)
            ->assertScript(CALENDAR_NO_BAND, true)
            ->assertScript(CALENDAR_HEAD_FORMS, $heads);
        Look::shot($page, 'calendar-week-one-'.$width.'x'.$height);
    }

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

it('draws each head of Week narrowed to one member as the whole date in Greek, whose dates run longer, at a laptop\'s 1280 pixels and in a desktop window', function () {
    $days = calendarElenaWorkday();
    $workday = calendarDay($days);
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '13:00', 'Beard trim', days: $days);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1280, 620)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday))
        ->waitForText('Cora Cut')
        ->click('#calendar-who')
        ->click('.admin-calendar-who__member[data-staff-id="'.LunaFixture::id('staff', 'Nikos Georgiou').'"] input')
        ->click('#calendar-who')
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Cora Cut', 'service' => 'Haircut'], SiteLanguage::Greek))
        ->assertNotPresent('.admin-calendar-columns');

    foreach ([[1280, 620], [1440, 900]] as [$width, $height]) {
        $page->resize($width, $height)
            ->assertScript(CALENDAR_SETTLED, true)
            ->assertScript(CALENDAR_HEAD_FORMS, 'full');
    }

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

it('fits the one grid\'s box to its own hours in a window taller than them, no band under the last hour, on a direct load and after the week, the view, the colours and the member change', function () {
    $days = calendarElenaWorkday();
    $workday = calendarDay($days);
    calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $monday = (new DateTimeImmutable($workday, new DateTimeZone('Europe/Athens')))->modify('monday this week');

    // Nikos works four hours a day that week, fewer than Elena, so his axis is the shorter.
    foreach ([1, 2, 3, 4, 5] as $ahead) {
        DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $nikos, 'date' => $monday->add(new DateInterval('P'.$ahead.'D'))->format('Y-m-d'), 'windows' => json_encode([['start' => '10:00', 'end' => '14:00']], JSON_THROW_ON_ERROR)]);
    }

    $week = Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday);
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $member = fn (int $id): string => '.admin-calendar-who__member[data-staff-id="'.$id.'"] input';
    $fits = fn (string $box) => $page->assertScript(CALENDAR_BOX, $box)->assertScript(CALENDAR_NO_BAND, true);

    // Narrowed to Elena by Who, which the tab remembers: her eleven hours and the header.
    $page->resize(1440, 900)
        ->navigate($week)
        ->waitForText('Cora Cut')
        ->click('#calendar-who')
        ->click($member($nikos))
        ->click('#calendar-who')
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $fits('694 true');

    // The same address loaded again, the one grid drawn from the start.
    $page->navigate($week)->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $fits('694 true');

    // The week after, which holds no booking, and back.
    $page->click('.admin-calendar-next')->assertDontSee('Cora Cut');
    $fits('694 true');
    $page->click('.admin-calendar-previous')->waitForText(calendarBlock('Cora Cut', 'Haircut'));
    $fits('694 true');

    // Day and back, Month and back.
    foreach (['day', 'month'] as $view) {
        $page->click(adminLine('admin/calendar.toolbar.views.'.$view))
            ->assertSeeIn('.admin-calendar-view [aria-pressed="true"]', adminLine('admin/calendar.toolbar.views.'.$view))
            ->assertNotPresent('.admin-calendar-grid .fc-timeGridWeek-view')
            ->click(adminLine('admin/calendar.toolbar.views.week'))
            ->waitForText(calendarBlock('Cora Cut', 'Haircut'));
        $fits('694 true');
    }

    // Coloured by status in place of by member.
    $page->select('.admin-calendar-colour-by', 'status')
        ->waitForText(adminLine(SettingsController::COLOUR_BY_SAVED))
        ->assertPresent('.admin-calendar-legend[data-colour-by="status"]');
    $fits('694 true');

    // Nikos in her place: six hours on the axis, an hour either side of his four.
    $page->click('#calendar-who')
        ->click($member($nikos))
        ->click($member($elena))
        ->click('#calendar-who')
        ->assertDontSee('Cora Cut')
        ->assertPresent('.admin-calendar-grid .fc-timeGridWeek-view');
    $fits('394 true');

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

/**
 * A point of the window, as a script's expression answering [x, y]: so far right of the first
 * element's left edge, and so far under the second's top edge or, the number negative, above its
 * foot.
 */
function calendarPoint(string $across, int $x, string $down, int $y): string
{
    $across = json_encode($across, JSON_THROW_ON_ERROR);
    $down = json_encode($down, JSON_THROW_ON_ERROR);
    $edge = $y < 0 ? 'bottom' : 'top';

    return "(() => { const across = document.querySelector({$across}).getBoundingClientRect(); const down = document.querySelector({$down}).getBoundingClientRect(); return [across.left + {$x}, down.{$edge} + {$y}]; })()";
}

/**
 * A script sending the page the mouse events the grid's library listens for, since the walk's own
 * mouse cannot keep its button down between two steps: "press" puts the button down on whatever
 * lies under the point given, "carry" moves the held pointer to the point in eight steps from the
 * point it left, and "drop" lets the button go at the point.
 */
function calendarHeld(string $step, string $point, string $from = '[0, 0]'): string
{
    return <<<JS
        (() => {
            const [x, y] = {$point};
            const [fromX, fromY] = {$from};
            const send = (target, kind, left, top) => target.dispatchEvent(new MouseEvent(kind, { bubbles: true, cancelable: true, view: window, button: 0, buttons: kind === 'mouseup' ? 0 : 1, clientX: left, clientY: top }));
            if ('{$step}' === 'press') {
                send(document.elementFromPoint(x, y), 'mousedown', x, y);
            } else if ('{$step}' === 'carry') {
                for (let step = 1; step <= 8; step++) {
                    send(document, 'mousemove', fromX + ((x - fromX) * step) / 8, fromY + ((y - fromY) * step) / 8);
                }
            } else {
                send(document, 'mouseup', x, y);
            }
            return '{$step}';
        })()
        JS;
}

/**
 * A script scrolling the page until the row of Day's columns keeps the edge named, its foot or
 * its top, 70 pixels inside the window, clear of the band along the window's own edge where a
 * held block scrolls the page, and scrolling the row itself as far as given; it answers how far
 * inside the window that edge lies.
 */
function calendarRowAt(string $edge, int $scroll): string
{
    return "(() => { const row = document.querySelector('.admin-calendar-columns__row'); const at = row.getBoundingClientRect(); window.scrollBy(0, '{$edge}' === 'foot' ? at.bottom - (window.innerHeight - 70) : at.top - 70); row.scrollTop = {$scroll}; const now = row.getBoundingClientRect(); return String(Math.round('{$edge}' === 'foot' ? window.innerHeight - now.bottom : now.top)); })()";
}

/**
 * Reads the scroll of Day's row of columns four times a second, for five seconds at most, until
 * it is at the end named, its foot or its top; answers how far from that end it is left.
 */
function calendarRowReaches(AwaitableWebpage|Webpage $page, string $edge): float
{
    $left = 0.0;

    for ($tries = 0; $tries < 20; $tries++) {
        $read = $page->script("(() => { const row = document.querySelector('.admin-calendar-columns__row'); return '{$edge}' === 'foot' ? row.scrollHeight - row.clientHeight - row.scrollTop : row.scrollTop; })()");
        assert(is_int($read) || is_float($read));
        $left = (float) $read;

        if ($left <= 1) {
            break;
        }

        $page->wait(0.25);
    }

    return $left;
}

it('scrolls Day\'s row of columns under a block held at its foot or at its top, one drag carrying a booking to an hour out of sight, and lands the drop on the hour under the pointer, in its own column and in another member\'s', function () {
    $days = calendarNextWednesday();
    $day = calendarDay($days);
    $date = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->format('d/m/Y');
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');

    // Both work to 20:00 that day, so a booking let go at 19:00 lies inside their hours.
    foreach ([$elena, $nikos] as $member) {
        DB::table('tenant_luna.shift_overrides')->insert(['staff_id' => $member, 'date' => $day, 'windows' => json_encode([['start' => '09:00', 'end' => '20:00']], JSON_THROW_ON_ERROR)]);
    }

    $early = '.fc-event[data-block-id="'.calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days).':0"]';
    $late = '.fc-event[data-block-id="'.calendarBooking('Lena Late', 'confirmed', 'Elena Papadaki', '18:00', days: $days).':0"]';
    $row = '.admin-calendar-columns__row';
    $grid = fn (int $member): string => calendarColumn($member).' .fc-timegrid-col[data-date="'.$day.'"]';
    $lane = fn (int $member, string $time): string => calendarPoint($grid($member), 24, calendarColumn($member).' .fc-timegrid-slot-lane[data-time="'.$time.':00"]', 4);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1280, 620)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$day))
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertScript(CALENDAR_AT_SCROLL_TIME, '');

    // The block pressed near its top, carried to eight pixels inside the row's edge and held
    // there until the row has scrolled to its end, then carried to the hour and let go.
    $carry = function (string $block, string $edge, int $scroll, int $member, string $time) use ($page, $row, $grid, $lane): void {
        $press = calendarPoint($block, 12, $block, 4);
        $hold = calendarPoint($grid($member), 24, $row, $edge === 'foot' ? -8 : 8);

        $page->assertScript(calendarRowAt($edge, $scroll), '70');
        $page->script(calendarHeld('press', $press));
        $page->script(calendarHeld('carry', $hold, $press));
        expect(calendarRowReaches($page, $edge))->toBeLessThanOrEqual(1.0);
        $page->script(calendarHeld('carry', $lane($member, $time), $hold));
        $page->script(calendarHeld('drop', $lane($member, $time)));
        $page->waitForText(adminLine('admin/bookings.move.confirm.title'));
    };

    // Down to 19:00 in her own column: nine hours on, three of them under the row's fold.
    $carry($early, 'foot', 60, $elena, '19:00');
    $page->assertSeeIn('.admin-move-confirm__from-to', $date.' 19:00')
        ->assertNotPresent('.admin-move-confirm__warning')
        ->click('.admin-move-confirm__keep')
        ->assertDontSee(adminLine('admin/bookings.move.confirm.title'));

    // Down to 19:00 in Nikos's column.
    $carry($early, 'foot', 60, $nikos, '19:00');
    $page->assertSeeIn('.admin-move-confirm__from-to', $date.' 19:00')
        ->assertSeeIn('.admin-move-confirm__change', adminLine('admin/bookings.move.confirm.member', ['service' => 'Haircut', 'from' => 'Elena Papadaki', 'to' => 'Nikos Georgiou']))
        ->click('.admin-move-confirm__keep')
        ->assertDontSee(adminLine('admin/bookings.move.confirm.title'));

    // Up from the row's foot to 09:00, held at the row's top, which the heads lie over.
    $carry($late, 'top', 2000, $elena, '09:00');
    $page->assertSeeIn('.admin-move-confirm__from-to', $date.' 09:00')
        ->click('.admin-move-confirm__keep')
        ->assertDontSee(adminLine('admin/bookings.move.confirm.title'))
        ->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

it('brings a block the keyboard reaches out from under the heads of Day\'s columns, the heads\' foot clear above it', function () {
    $days = calendarElenaWorkday();
    $block = '.fc-event[data-block-id="'.calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days).':0"]';
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    // How far the block's top lies under the foot of its column's head.
    $clear = "(() => { const block = document.querySelector('".$block."'); const head = block.closest('.admin-calendar-column').querySelector('.admin-calendar-column__head'); return (document.activeElement === block ? 'focused ' : 'not focused ') + Math.round(block.getBoundingClientRect().top - head.getBoundingClientRect().bottom); })()";

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1280, 620)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertScript(CALENDAR_AT_SCROLL_TIME, '');

    // The row scrolled on by two hours: the block lies behind the heads, its foot at theirs.
    $page->script("document.querySelector('.admin-calendar-columns__row').scrollTop = 180");
    $page->assertScript($clear, 'not focused -60');

    // The Tab key from the head's menu lands on the block, and the row scrolls it out, eight
    // pixels under the heads, room for a focus ring.
    $page->keys(calendarColumn($elena).' .admin-calendar-head__menu', 'Tab')
        ->assertScript($clear, 'focused 8')
        ->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

/**
 * A script answering how the block given is focused, as "<whether the focus is on it> <whether
 * the keyboard put it there> <whether a ring in the theme colour is drawn four pixels out from
 * it, past a gap> <its outline's style>".
 */
function calendarRingOf(string $block): string
{
    $block = json_encode($block, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const block = document.querySelector({$block});
            const probe = document.createElement('span');
            probe.style.color = 'var(--primary)';
            block.appendChild(probe);
            const tone = getComputedStyle(probe).color;
            probe.remove();
            const style = getComputedStyle(block);
            return [document.activeElement === block, block.matches(':focus-visible'), style.boxShadow.includes(tone + ' 0px 0px 0px 4px'), style.outlineStyle].join(' ');
        })()
        JS;
}

it('rings a focused block that wears a state\'s outline in the theme colour, outside the outline and past a gap, for the keyboard and not for the pointer', function () {
    $days = calendarElenaWorkday();
    $request = '.fc-event[data-block-id="'.calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '10:00', days: $days).':0"]';
    $unpaid = '.fc-event[data-block-id="'.calendarBooking('Una Paid', 'awaiting_payment', 'Elena Papadaki', '12:00', days: $days).':0"]';
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText(calendarBlock('Una Paid', 'Haircut'))
        ->assertScript(calendarRingOf($request), 'false false false solid');

    // The secondary button focuses the block as any press does and opens nothing.
    calendarPlaywright($page)->locator($request)->click(['button' => 'right', 'force' => true]);
    $page->assertScript(calendarRingOf($request), 'true false false solid');

    // The Tab key from the head's menu reaches the request, then the booking awaiting payment.
    $page->keys(calendarColumn($elena).' .admin-calendar-head__menu', 'Tab')
        ->assertScript(calendarRingOf($request), 'true true true solid')
        ->assertScript(calendarOutlineOf($request, CALENDAR_REQUEST_OUTLINE), 'solid 2px true')
        ->keys($request, 'Tab')
        ->assertScript(calendarRingOf($unpaid), 'true true true dashed')
        ->assertScript(calendarOutlineOf($unpaid, 'var(--primary)'), 'dashed 2px true')
        ->assertScript(calendarRingOf($request), 'false false false solid')
        ->assertNoJavaScriptErrors();
});

/**
 * A script answering how the block given is drawn under its focus, as "<whether the focus is on
 * it> <the content of the library's own focus layer over it, none where there is no layer>
 * <whether its harness stands above every other block's harness in its column>".
 */
function calendarFocusLayerOf(string $block): string
{
    $block = json_encode($block, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const block = document.querySelector({$block});
            const harness = block.closest('.fc-timegrid-event-harness');
            const others = [...harness.parentElement.children].filter((each) => each !== harness && each.classList.contains('fc-timegrid-event-harness'));
            const lift = (each) => Number(getComputedStyle(each).zIndex);
            return [document.activeElement === block, getComputedStyle(block, '::after').content, others.length > 0 && others.every((each) => lift(each) < lift(harness))].join(' ');
        })()
        JS;
}

it('draws a focused block undimmed by the library\'s own focus layer and above the blocks beside it, so its ring is never covered, plain or outlined', function () {
    $days = calendarElenaWorkday();
    $plain = '.fc-event[data-block-id="'.calendarBooking('Pia Plain', 'confirmed', 'Elena Papadaki', '10:00', days: $days).':0"]';
    $request = '.fc-event[data-block-id="'.calendarBooking('Rita Request', 'awaiting_approval', 'Elena Papadaki', '11:00', days: $days).':0"]';
    calendarBooking('Nell Next', 'confirmed', 'Elena Papadaki', '12:00', days: $days);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText(calendarBlock('Nell Next', 'Haircut'))
        ->assertScript(calendarFocusLayerOf($plain), 'false none false');

    // A press of the pointer focuses the block too: no layer dims it, and with no ring to show it
    // stays among its neighbours.
    calendarPlaywright($page)->locator($plain)->click(['button' => 'right', 'force' => true]);
    $page->assertScript(calendarFocusLayerOf($plain), 'true none false');

    // The keyboard's focus, on the plain block and then on the outlined one under it.
    $page->keys(calendarColumn($elena).' .admin-calendar-head__menu', 'Tab')
        ->assertScript(calendarFocusLayerOf($plain), 'true none true')
        ->keys($plain, 'Tab')
        ->assertScript(calendarFocusLayerOf($request), 'true none true')
        ->assertScript(calendarFocusLayerOf($plain), 'false none false')
        ->assertNoJavaScriptErrors();
});

/**
 * A script answering an empty string where the time grid's box, Day's row of columns or the one
 * grid, scrolled to the window's top, ends at least two pixels above the bottom bar's top, holds
 * its hours scrolling inside it while the page stays where it is, and has nothing standing over
 * its top or its foot. The bar's top is read where it rests, the slide that puts it away on a
 * scroll down left out. Otherwise what was found.
 */
const CALENDAR_ABOVE_BAR = <<<'JS'
    (() => {
        const bar = document.querySelector('.admin-bottom-bar');
        const row = document.querySelector('.admin-calendar-columns__row');
        const box = row ?? document.querySelector('.admin-calendar-grid');
        const scroller = row ?? [...(box?.querySelectorAll('.fc-scroller') ?? [])].find((candidate) => candidate.querySelector('.fc-timegrid-body') !== null);
        if (bar === null || box === null || scroller === undefined) {
            return 'not on the page';
        }
        const top = window.innerHeight - parseFloat(getComputedStyle(bar).bottom) - bar.getBoundingClientRect().height;
        box.scrollIntoView({ block: 'start' });
        const at = box.getBoundingClientRect();
        const found = [];
        if (top - at.bottom < 2) {
            found.push('the foot ' + (top - at.bottom).toFixed(2) + 'px above the bar');
        }
        if (scroller.scrollHeight <= scroller.clientHeight + 1) {
            found.push('the hours not inside the box, ' + scroller.scrollHeight + ' in ' + scroller.clientHeight);
        }
        const page = window.scrollY;
        const before = scroller.scrollTop;
        scroller.scrollTop = 0;
        scroller.scrollTop = 40;
        if (scroller.scrollTop !== 40) {
            found.push('the box scrolled ' + scroller.scrollTop + ' for 40');
        }
        if (window.scrollY !== page || Math.abs(box.getBoundingClientRect().top - at.top) > 0.5) {
            found.push('the page moved with the hours');
        }
        scroller.scrollTop = before;
        for (const y of [at.top + 1, at.bottom - 1]) {
            const under = document.elementFromPoint(at.left + at.width / 2, y);
            if (under === null || !box.contains(under)) {
                found.push('covered ' + Math.round(y) + 'px down by ' + (under?.className ?? 'nothing'));
            }
        }
        return found.join(', ');
    })()
    JS;

it('scrolls the time grids inside their own box below the desktop width, opening at the scroll time with the heads at its top and the box\'s foot clear above the bottom bar, on Day\'s row of columns and on Week narrowed to one member, 820 and 390 pixels wide', function () {
    $days = calendarElenaWorkday();
    $workday = calendarDay($days);
    $block = '.fc-event[data-block-id="'.calendarBooking('Cora Cut', 'confirmed', 'Elena Papadaki', '10:00', days: $days).':0"]';
    calendarBooking('Bo South', 'confirmed', 'Nikos Georgiou', '13:00', 'Beard trim', days: $days);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');
    $nikos = LunaFixture::id('staff', 'Nikos Georgiou');
    $day = Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$workday);
    // How far the block's top lies under the foot of its column's head.
    $clear = "(() => { const block = document.querySelector('".$block."'); const head = block.closest('.admin-calendar-column').querySelector('.admin-calendar-column__head'); return (document.activeElement === block ? 'focused ' : 'not focused ') + Math.round(block.getBoundingClientRect().top - head.getBoundingClientRect().bottom); })()";

    // Day's row of columns past the desktop width with the bottom bar still up, then on a phone.
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    foreach ([[820, 700], [390, 844]] as [$width, $height]) {
        $page->resize($width, $height)
            ->navigate($day)
            ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
            ->assertPresent('.admin-bottom-bar')
            ->assertScript(CALENDAR_AT_SCROLL_TIME, '')
            ->assertScript(CALENDAR_HEAD_STAYS, '')
            ->assertScript(CALENDAR_ABOVE_BAR, '');
    }

    // On a shorter phone the row scrolled on by two hours hides the block behind the heads; the
    // Tab key from the head's menu brings it out eight pixels under them, room for a focus ring.
    $page->resize(390, 664)->wait(0.25);
    $page->script("document.querySelector('.admin-calendar-columns__row').scrollTop = 180");
    $page->assertScript($clear, 'not focused -60')
        ->keys(calendarColumn($elena).' .admin-calendar-head__menu', 'Tab')
        ->assertScript($clear, 'focused 8');
    Look::shot($page, 'calendar-day-columns-390x664');

    // Week narrowed to one member is the one grid: its eleven hours in the box, its day header at
    // the top of it, past the desktop width with the bar up and once the window narrows to a
    // shorter phone.
    $page->resize(820, 700)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.$workday))
        ->waitForText('Cora Cut')
        ->click('#calendar-who')
        ->click('.admin-calendar-who__member[data-staff-id="'.$nikos.'"] input')
        ->click('#calendar-who')
        ->waitForText(calendarBlock('Cora Cut', 'Haircut'))
        ->assertNotPresent('.admin-calendar-week')
        ->assertScript(CALENDAR_AT_SCROLL_TIME, '')
        ->assertScript(CALENDAR_HEAD_STAYS, '')
        ->assertScript(CALENDAR_ABOVE_BAR, '');

    $page->resize(390, 664)->wait(0.25);
    $page->assertNotPresent('.admin-calendar-week')
        ->assertScript(CALENDAR_HEAD_STAYS, '')
        ->assertScript(CALENDAR_ABOVE_BAR, '');
    Look::shot($page, 'calendar-week-one-390x664');

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

/**
 * A script answering, of each block given by its minutes, whether its title's box lies inside the
 * block's own box once the fonts are in: an empty string where every title is on a line at least
 * 14 pixels tall with two pixels to spare above and below it, or, in a block too short for that,
 * centred in what the block has; else each block that fails as "<minutes>: <spare above>/<spare
 * below> on a <line>px line in <height>px", a comma between.
 *
 * @param  array<int|string, string>  $blocks  each block's selector, by its minutes, and by what
 *                                             it holds beside them where two share their minutes
 */
function calendarTitleInside(array $blocks): string
{
    $blocks = json_encode($blocks, JSON_THROW_ON_ERROR);

    return <<<JS
        document.fonts.ready.then(() => {
            const found = [];
            for (const [minutes, selector] of Object.entries({$blocks})) {
                const block = document.querySelector(selector);
                const title = block?.querySelector('.admin-calendar-block__title');
                if (!title) {
                    found.push(minutes + ': not on the page');
                    continue;
                }
                const box = block.getBoundingClientRect();
                const line = title.getBoundingClientRect();
                const above = line.top - box.top;
                const below = box.bottom - line.bottom;
                const due = Math.min(2, Math.max(0, (box.height - 14) / 2));
                if (line.height < 13.95 || above < due - 0.05 || below < due - 0.05) {
                    found.push(minutes + ': ' + above.toFixed(1) + '/' + below.toFixed(1) + ' on a ' + line.height.toFixed(1) + 'px line in ' + box.height.toFixed(1) + 'px');
                }
            }
            return found.join(', ');
        })
        JS;
}

it('keeps a block\'s title inside the block from the shortest the grid draws to the first that takes the small text\'s own line, the title centred where the block has no room for its padding, a short block\'s badge, closure mark and processing gap among them, in Day and in Week, in English and in Greek', function (SiteLanguage $language, string $customer) {
    $days = calendarNextWednesday();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'date' => calendarDay($days), 'start_time' => '18:00', 'end_time' => '19:00', 'reason' => 'Training']);
    $blocks = [];

    foreach ([5 => '09:00', 10 => '10:00', 15 => '11:00', 16 => '12:00', 20 => '13:00', 22 => '14:00', 23 => '15:00'] as $minutes => $time) {
        $blocks[$minutes] = '.fc-event[data-block-id="'.calendarBooking($customer, 'confirmed', 'Nikos Georgiou', $time, days: $days, minutes: $minutes).':0"]';
    }

    // Short blocks whose title's line holds more than its words: a request's badge, drawn in
    // Day's wide column, the closure's mark, drawn at every width, and a processing gap inside
    // its dashed edge.
    $blocks['15 awaiting approval'] = '.fc-event[data-block-id="'.calendarBooking($customer, 'awaiting_approval', 'Nikos Georgiou', '16:00', days: $days, minutes: 15).':0"]';
    $blocks['15 inside a closure'] = '.fc-event[data-block-id="'.calendarBooking($customer, 'confirmed', 'Nikos Georgiou', '18:00', days: $days, minutes: 15).':0"]';
    $processed = calendarBooking($customer, 'confirmed', 'Nikos Georgiou', '17:00', days: $days, minutes: 30);
    $processedId = DB::table('tenant_luna.bookings')->where('uuid', $processed)->value('id');
    assert(is_int($processedId));
    DB::table('tenant_luna.booking_items')->where('booking_id', $processedId)->update(['processing_ends_utc' => DB::raw("end_utc + interval '10 minutes'")]);
    $blocks['10 processing gap'] = '.fc-event[data-block-id="'.$processed.':0:gap"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText($customer)
        ->assertScript(CALENDAR_SETTLED, true)
        ->assertScript(calendarBadgeAt($blocks['15 awaiting approval'], null, 0), 'drawn')
        ->assertScript(calendarTitleInside($blocks), '');

    // Week narrowed to one member: seven columns, where the badge gives its line to the title
    // and the closure's mark is still drawn.
    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.calendarDay($days)))
        ->waitForText($customer)
        ->click('#calendar-who')
        ->click('.admin-calendar-who__member[data-staff-id="'.LunaFixture::id('staff', 'Elena Papadaki').'"] input')
        ->click('#calendar-who')
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => $customer, 'service' => 'Haircut'], $language))
        ->assertNotPresent('.admin-calendar-week')
        ->assertScript(CALENDAR_SETTLED, true)
        ->assertScript("getComputedStyle(document.querySelector('".$blocks['15 inside a closure']." .admin-calendar-inside__mark')).display", 'inline')
        ->assertScript(calendarTitleInside($blocks), '');

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
})->with([
    'in English' => [SiteLanguage::English, 'Gaby Quigley'],
    'in Greek' => [SiteLanguage::Greek, "\u{0386}\u{03b3}\u{03b3}\u{03b5}\u{03bb}\u{03bf}\u{03c2} \u{03a1}\u{03ae}\u{03b3}\u{03b1}\u{03c2}"],
]);

/**
 * How a block's badge is drawn with the block's body held at a width, once the fonts are in, as
 * "<the body's width> drawn" where the badge is whole on the title's line, "cut" where it is drawn
 * and not whole, "heard" where it is not drawn and a screen reader still has its word, and "gone"
 * where a reader has not; with no width, the body is left at its own and the answer is the word
 * alone. A pill the line's ellipsis takes away keeps its box, so a drawn badge is
 * whole where a point just inside each of its ends hits it. Where the title's words keep less than
 * the room named, from where they begin to the line's end, the answer says how much they keep.
 */
function calendarBadgeAt(string $block, ?int $width, int $room): string
{
    $held = $width === null ? '' : "body.style.width = '".$width."px'; ";
    $named = $width === null ? "''" : "Math.round(body.getBoundingClientRect().width) + ' '";

    return "document.fonts.ready.then(() => { const body = document.querySelector('".$block." .admin-calendar-block__body'); ".$held."body.scrollIntoView({ block: 'center' }); const line = body.querySelector('.admin-calendar-block__title'); const badge = line.querySelector('.admin-calendar-badge'); const box = badge.getBoundingClientRect(); const end = line.getBoundingClientRect().right; const words = document.createRange(); words.selectNodeContents(line.lastChild); const keeps = end - words.getBoundingClientRect().left; const look = getComputedStyle(badge); const read = look.display !== 'none' && look.visibility !== 'hidden' && badge.closest('[aria-hidden=\"true\"]') === null; const whole = box.right <= end + 0.5 && [box.left + 6, box.right - 6].every((x) => document.elementFromPoint(x, box.top + box.height / 2) === badge); const drawn = box.width > 1 && box.height > 1; return ".$named." + (drawn ? (whole ? 'drawn' : 'cut') : (read ? 'heard' : 'gone')) + (keeps < ".$room." ? ' keeping ' + keeps.toFixed(1) : ''); })";
}

/**
 * What keeps a block at its own width from showing its customer's name on its title's line, once
 * the fonts are in: its badge drawn, or the name not lying whole inside the line with 16 pixels to
 * the line's end, more than an ellipsis takes. An empty string where the name shows.
 */
function calendarNameHidden(string $block, string $customer): string
{
    return "document.fonts.ready.then(() => { const line = document.querySelector('".$block." .admin-calendar-block__title'); const row = line.getBoundingClientRect(); const name = document.createRange(); name.setStart(line.lastChild, 0); name.setEnd(line.lastChild, ".strlen($customer).'); const at = name.getBoundingClientRect(); const found = []; if (name.toString() !== '.json_encode($customer, JSON_THROW_ON_ERROR).") { found.push('another name'); } if (line.querySelector('.admin-calendar-badge').getBoundingClientRect().width > 1) { found.push('its badge drawn'); } if (at.left < row.left - 0.5 || at.right > row.right - 16) { found.push('its name ending ' + (row.right - at.right).toFixed(1) + 'px from the end of a line ' + row.width.toFixed(1) + 'px long'); } return found.join(', '); })";
}

it('draws a block\'s badge whole with room for the title after it from one width of the block\'s body on, and not at all under it, where the title has the line and a screen reader the badge\'s word, in Greek, where the badge is widest, beside the closure\'s mark and alone; and in Week narrowed to one member at a laptop\'s width a block with a badge shows its customer\'s name and keeps its outline', function () {
    $days = calendarNextWednesday();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'date' => calendarDay($days), 'start_time' => '14:00', 'end_time' => '18:00', 'reason' => 'Training']);
    $alone = '.fc-event[data-block-id="'.calendarBooking('Una Paid', 'awaiting_payment', 'Nikos Georgiou', '11:00', days: $days).':0"]';
    $beside = '.fc-event[data-block-id="'.calendarBooking('Ula Unpaid', 'awaiting_payment', 'Nikos Georgiou', '16:00', days: $days).':0"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText('Ula Unpaid')
        ->assertScript("document.querySelector('".$alone." .admin-calendar-badge').textContent", adminLine('admin/shell.kit.statuses.awaiting_payment', [], SiteLanguage::Greek))
        ->assertScript("document.querySelector('".$beside." .admin-calendar-badge').textContent", adminLine('admin/shell.kit.statuses.awaiting_payment', [], SiteLanguage::Greek))
        ->assertMissing($alone.' .admin-calendar-inside')
        ->assertPresent($beside.' .admin-calendar-inside');

    // The badge takes some 154 pixels of the line and the closure's mark some 22 before it. A pixel
    // under the width the badge is not drawn and the title has the line, all of it or all of it
    // past the mark. At the width the badge is whole and the title keeps 16 pixels and more after
    // it, the mark before it or not.
    $page->assertScript(calendarBadgeAt($alone, 194, 192), '194 heard')
        ->assertScript(calendarBadgeAt($beside, 194, 169), '194 heard')
        ->assertScript(calendarBadgeAt($alone, 195, 16), '195 drawn')
        ->assertScript(calendarBadgeAt($beside, 195, 16), '195 drawn');

    // Week narrowed to one member: seven columns in a 1440 pixel window, a block's body far under
    // the width, so the badge gives its line to the name and the outline alone tells the state.
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=week&date='.calendarDay($days)))
        ->waitForText('Ula Unpaid')
        ->click('#calendar-who')
        ->click('.admin-calendar-who__member[data-staff-id="'.LunaFixture::id('staff', 'Elena Papadaki').'"] input')
        ->click('#calendar-who')
        ->waitForText(calendarBlock('Ula Unpaid', 'Haircut'))
        ->assertNotPresent('.admin-calendar-week');

    foreach ([[$alone, 'Una Paid'], [$beside, 'Ula Unpaid']] as [$block, $customer]) {
        $page->assertScript(calendarNameHidden($block, $customer), '')
            ->assertScript(calendarOutlineOf($block, 'var(--primary)'), 'dashed 2px true');
    }

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

it('draws a block\'s badge whole on its title\'s line at the block\'s own width in Day in a desktop window, in Greek, where the badge is widest, beside the closure\'s mark and alone, the title keeping room after it', function () {
    $days = calendarNextWednesday();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'date' => calendarDay($days), 'start_time' => '14:00', 'end_time' => '18:00', 'reason' => 'Training']);
    $alone = '.fc-event[data-block-id="'.calendarBooking('Una Paid', 'awaiting_payment', 'Nikos Georgiou', '11:00', days: $days).':0"]';
    $beside = '.fc-event[data-block-id="'.calendarBooking('Ula Unpaid', 'awaiting_payment', 'Nikos Georgiou', '16:00', days: $days).':0"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText('Ula Unpaid')
        ->assertPresent($beside.' .admin-calendar-inside')
        ->assertScript(CALENDAR_SETTLED, true)
        ->assertScript(calendarBadgeAt($alone, null, 16), 'drawn')
        ->assertScript(calendarBadgeAt($beside, null, 16), 'drawn');
    Look::shot($page, 'calendar-greek-1440-day');

    $page->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});

/**
 * How the closure's pill is drawn in a block as its body is held at each of the widths in turn,
 * as "<width> mark" for the mark alone and "<width> words" for its words, joined by commas. Where
 * the pill is worded and the title keeps less than the room named, of its line past the last
 * pill, the answer says how much it keeps.
 *
 * @param  list<int>  $widths
 */
function calendarPillAt(string $block, array $widths, int $room): string
{
    return "(() => { const body = document.querySelector('".$block." .admin-calendar-block__body'); const pills = [...body.querySelectorAll('.admin-calendar-inside, .admin-calendar-badge')]; const last = pills[pills.length - 1]; return ".json_encode($widths).".map((width) => { body.style.width = width + 'px'; const keeps = body.getBoundingClientRect().right - last.getBoundingClientRect().right - parseFloat(getComputedStyle(last).marginRight); const worded = getComputedStyle(body.querySelector('.admin-calendar-inside__mark')).display === 'none'; return width + (worded ? ' words' : ' mark') + (worded && keeps < ".$room." ? ' keeping ' + keeps.toFixed(1) : ''); }).join(', '); })()";
}

it('words the closure\'s pill only where the title keeps its room beside it, in Greek, where the pills are widest: the mark alone under one width of the block\'s body and under a wider one where a badge shares the line, and from each on the title keeping 89 pixels of its line after the pill alone and 79 after the pill and the badge', function () {
    $days = calendarNextWednesday();
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => LunaFixture::id('staff', 'Nikos Georgiou'), 'date' => calendarDay($days), 'start_time' => '14:00', 'end_time' => '18:00', 'reason' => 'Training']);
    $alone = '.fc-event[data-block-id="'.calendarBooking('Ina Inside', 'confirmed', 'Nikos Georgiou', '14:00', days: $days).':0"]';
    $beside = '.fc-event[data-block-id="'.calendarBooking('Ula Unpaid', 'awaiting_payment', 'Nikos Georgiou', '16:00', days: $days).':0"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $size = calendarPlaywright($page)->viewportSize();
    $page->resize(1440, 900)
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.calendarDay($days)))
        ->waitForText('Ula Unpaid')
        ->assertScript("document.querySelector('".$beside." .admin-calendar-badge').textContent", adminLine('admin/shell.kit.statuses.awaiting_payment', [], SiteLanguage::Greek))
        ->assertScript("document.querySelector('".$alone." .admin-calendar-inside__words').textContent", adminLine('admin/calendar.badges.closure', [], SiteLanguage::Greek))
        ->assertMissing($alone.' .admin-calendar-badge');

    // The pill alone takes some 150 pixels of the line in words, and the title keeps 89 past it;
    // with a badge after it the two take some 304, and the title keeps 79. The title has that
    // room at the width where the pill's words begin and at every width past it.
    $page->assertScript('['.calendarPillAt($alone, [200, 220, 240, 241, 300], 89).', '.calendarPillAt($beside, [300, 340, 384, 385, 440], 79)."].join('; ')", '200 mark, 220 mark, 240 mark, 241 words, 300 words; 300 mark, 340 mark, 384 mark, 385 words, 440 words')
        ->resize($size['width'], $size['height'])
        ->assertNoJavaScriptErrors();
});
