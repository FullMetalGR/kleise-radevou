<?php

use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use Pest\Browser\Playwright\Page;
use Tests\Browser\BrowserTestCase;
use Tests\Browser\Support\Admin;
use Tests\Support\LunaFixture;

/*
 * The Move form in a real browser: an owner opens a movable booking on the Calendar's Day,
 * presses Move, picks another time from the day's free times, saves, confirms and reads the
 * booking at its new time with the move on its trail; and picks a time outside the member's
 * working hours, reads the closed-time warning naming the booking and the member's hours that
 * day, and the move lands on Move anyway with the warning accepted.
 *
 * Dragging, with the real mouse: on Day a block dragged to another time in its column and onto
 * Nikos's column, each confirmed and then drawn once, where the server put it, with the move on
 * its trail; dragged onto another of Elena's bookings, refused before any confirmation with the
 * server's sentence and the block back; Keep it leaving everything as it was. On Week, a row per
 * member, a chip dragged along Elena's row to the next day keeps its hour, and onto Nikos's row
 * the booking becomes his; on Month a chip dragged to the next day keeps its hour too. Onto
 * closed time the same drags open the warning first: on Day onto a closure of the business, named
 * with its window and reason, where Cancel puts the block back and writes nothing, then onto
 * Nikos's column over his own closure, moved on Move anyway; and on Week onto Elena's Saturday,
 * her day off, cancelled once and then moved. An owner who reads Greek is told the same closure
 * as a Greek sentence, the day behind its article and the hours behind a comma.
 *
 * The booking is written straight to luna's rows on the next Wednesday in Athens, a day Elena
 * works 09:00-13:00 and 14:00-18:00, whole days from today, since the browser and the
 * application both read the real clock here.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** The next Wednesday after the tenant's today, in Athens, as a date. */
function moveWednesday(): string
{
    $today = new DateTimeImmutable('now', new DateTimeZone('Europe/Athens'));
    $days = (3 - (int) $today->format('N') + 7) % 7 ?: 7;

    return $today->add(new DateInterval('P'.$days.'D'))->format('Y-m-d');
}

/** An Athens wall time on a day as the move's read spells the start. */
function moveStart(string $day, string $time): string
{
    return (new DateTimeImmutable($day.' '.$time, new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
}

/** A confirmed Haircut of luna with the member named (Elena by default) at the Athens time given on the day given; its uuid. */
function moveBooking(string $customer, string $day, string $time, string $staff = 'Elena Papadaki'): string
{
    $uuid = (string) Str::uuid();
    $start = new DateTimeImmutable($day.' '.$time, new DateTimeZone('Europe/Athens'));
    $end = $start->add(new DateInterval('PT45M'));
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => $uuid, 'status' => 'confirmed', 'customer_name' => $customer,
        'customer_email' => Str::slug($customer).'@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => LunaFixture::id('services', 'Haircut'),
        'staff_id' => LunaFixture::id('staff', $staff),
        'start_utc' => $utc($start), 'end_utc' => $utc($end),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($end),
        'price_minor' => 3500, 'blocks' => true,
    ]);

    return $uuid;
}

/** The booking's start as the database holds it, in Athens. */
function moveStartOf(string $uuid): string
{
    $start = DB::table('tenant_luna.booking_items')
        ->join('tenant_luna.bookings', 'bookings.id', '=', 'booking_items.booking_id')
        ->where('bookings.uuid', $uuid)->value('start_utc');
    assert(is_string($start));

    return (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('H:i');
}

/** The booking's start as the database holds it, in Athens, with its date. */
function moveWhenOf(string $uuid): string
{
    $start = DB::table('tenant_luna.booking_items')
        ->join('tenant_luna.bookings', 'bookings.id', '=', 'booking_items.booking_id')
        ->where('bookings.uuid', $uuid)->value('start_utc');
    assert(is_string($start));

    return (new DateTimeImmutable($start, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i');
}

/** A closure of luna's on the day from start to end with its reason: the member's, or the business's with none named. */
function moveClosure(?string $staff, string $day, string $start, string $end, string $reason): void
{
    DB::table('tenant_luna.blackouts')->insert([
        'staff_id' => $staff === null ? null : LunaFixture::id('staff', $staff),
        'date' => $day, 'start_time' => $start, 'end_time' => $end, 'reason' => $reason,
    ]);
}

/** The warning's words for a window on a day: the weekday, the date as luna writes it, and the times. */
function moveOn(string $day, string $start, string $end): string
{
    $date = new DateTimeImmutable($day, new DateTimeZone('Europe/Athens'));
    $named = adminLine('admin/bookings.move.confirm.days.'.strtolower($date->format('l')), ['date' => $date->format('d/m/Y')]);

    return adminLine('admin/bookings.move.confirm.on_day_window', ['day' => $named, 'start' => $start, 'end' => $end]);
}

/**
 * The warning's words for a member's two windows of a day, as English lists two.
 *
 * @param  array{string, string}  $first
 * @param  array{string, string}  $second
 */
function moveWindows(array $first, array $second): string
{
    $window = fn (string $start, string $end): string => adminLine('admin/bookings.move.confirm.window', ['start' => $start, 'end' => $end]);

    return $window(...$first).' and '.$window(...$second);
}

/**
 * Opens the booking's block on the day into the dialog and presses Move.
 *
 * @return Webpage
 */
function moveOpen(BrowserTestCase $test, string $uuid, string $day)
{
    $page = Admin::signedIn($test, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view=day&date='.$day))
        ->waitForText(adminLine('admin/calendar.grid.block', ['customer' => 'Cora Cut', 'service' => 'Haircut']));

    return $page->click('.fc-event[data-block-id="'.$uuid.':0"]')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->click('.admin-booking-move')
        ->waitForText(adminLine('admin/bookings.move.time_help'));
}

it('moves a booking from the dialog to another free time and reads it at the new time with the move on its trail', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    $date = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->format('d/m/Y');

    $page = moveOpen($this, $uuid, $day);
    $page->assertSeeIn('.admin-move__now', 'Haircut')
        ->assertSeeIn('.admin-move__why', adminLine('admin/bookings.move.save_why'))
        ->select('#move-time', moveStart($day, '11:00'))
        ->click('.admin-move__save')
        ->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertSeeIn('.admin-move-confirm__from-to', $date.' 11:00')
        ->assertNotPresent('.admin-move-confirm__warning')
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'))
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-items__row', $date.' 11:00')
        ->assertSeeIn('.admin-booking-trail', adminLine('admin/bookings.trail.actions.rescheduled'))
        ->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('11:00');
});

it('warns where the chosen time is outside the member\'s working hours, and the move lands once confirmed', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');

    $page = moveOpen($this, $uuid, $day);
    $page->select('#move-time', moveStart($day, '13:00'))
        ->click('.admin-move__save')
        ->waitForText(adminLine('admin/bookings.move.confirm.closed_title'))
        ->assertSeeIn('.admin-move-confirm__booking', adminLine('admin/bookings.move.confirm.booking', ['customer' => 'Cora Cut', 'services' => 'Haircut']))
        ->assertSeeIn('.admin-move-confirm__warning', adminLine('admin/bookings.move.confirm.outside_hours', ['staff' => 'Elena Papadaki', 'hours' => moveWindows(['09:00', '13:00'], ['14:00', '18:00'])]))
        ->assertSeeIn('.admin-move-confirm__confirm', adminLine('admin/bookings.move.confirm.move_anyway'))
        ->assertSeeIn('.admin-move-confirm__keep', adminLine('admin/bookings.move.confirm.cancel'))
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'))
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-trail', adminLine('admin/bookings.trail.facts.overridden'))
        ->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('13:00');
});

/** The booking's member as the database holds it, by name. */
function moveStaffOf(string $uuid): string
{
    $name = DB::table('tenant_luna.booking_items')
        ->join('tenant_luna.bookings', 'bookings.id', '=', 'booking_items.booking_id')
        ->join('tenant_luna.staff', 'staff.id', '=', 'booking_items.staff_id')
        ->where('bookings.uuid', $uuid)->value('staff.name');
    assert(is_string($name));

    return $name;
}

/** The Playwright page under the plugin's page, for the mouse's own drag with positions. */
function movePlaywright(AwaitableWebpage|Webpage $page): Page
{
    return $page instanceof AwaitableWebpage ? $page->page() : throw new LogicException('The walk hands the awaitable page.');
}

/** A Day column's time grid by its member's name. */
function moveColumn(string $staff, string $day): string
{
    return '.admin-calendar-column[data-staff-id="'.LunaFixture::id('staff', $staff).'"] .fc-timegrid-col[data-date="'.$day.'"]';
}

/**
 * Drags the booking's block with the mouse, pressed near its top edge, onto the time grid column
 * given, so the block's start lands on the Athens time given there: the press's offset from its
 * own slot is kept at the target, as the grid moves a block by the slots the pointer crossed.
 * Forced, since the grid's slot rows sit over its columns where the pointer lands.
 */
function moveDrag(AwaitableWebpage|Webpage $page, string $uuid, string $from, string $target, string $to): void
{
    $block = '.fc-event[data-block-id="'.$uuid.':0"]';
    $y = $page->script(<<<JS
        () => {
            const block = document.querySelector('{$block}');
            const column = document.querySelector('{$target}');
            const lane = (grid, time) => grid.querySelector('.fc-timegrid-slot-lane[data-time="' + time + ':00"]').getBoundingClientRect().top;
            const press = block.getBoundingClientRect().top + 4;

            return lane(column.closest('.fc'), '{$to}') + (press - lane(block.closest('.fc'), '{$from}')) - column.getBoundingClientRect().top;
        }
        JS);
    assert(is_int($y) || is_float($y));

    $playwright = movePlaywright($page);
    $playwright->locator($block)->dragTo($playwright->locator($target), ['sourcePosition' => ['x' => 12, 'y' => 4], 'targetPosition' => ['x' => 24, 'y' => $y], 'steps' => 12, 'force' => true]);
}

/**
 * Waits until the booking's block is drawn once, on the column given, at the Athens time given.
 *
 * @return Webpage
 */
function moveDrawn(AwaitableWebpage|Webpage $page, string $uuid, string $column, string $time)
{
    $block = '.fc-event[data-block-id="'.$uuid.':0"]';

    // The awaitable page retries an assertion until it holds or the plugin's timeout passes; the
    // Playwright page's own waitForFunction sends nothing in this plugin.
    return $page->assertScript(<<<JS
        () => document.querySelectorAll('{$block}').length === 1
            && document.querySelector('{$column}')?.querySelector('{$block} .admin-calendar-block__time')?.textContent?.startsWith('{$time}') === true
        JS, true)
        ->assertCount($block, 1)
        ->assertCount($column.' '.$block, 1);
}

/**
 * The calendar's Day on the day, as the owner, or the view named, once Cora Cut's booking is
 * drawn: a block names her on Day, a chip on Week and Month.
 *
 * @return Webpage
 */
function moveDay(BrowserTestCase $test, string $day, string $view = 'day')
{
    return Admin::signedIn($test, 'luna', Admin::owner('luna'))
        ->navigate(Admin::url('luna', Admin::CALENDAR.'?view='.$view.'&date='.$day))
        ->waitForText('Cora Cut');
}

/** A day of a member's row on Week, by the member's name. */
function moveCell(string $staff, string $day): string
{
    return '.admin-calendar-week__cell[data-row="member-'.LunaFixture::id('staff', $staff).'"][data-date="'.$day.'"]';
}

/** A day of Month. */
function moveMonthDay(string $day): string
{
    return '.admin-calendar-month__day[data-date="'.$day.'"]';
}

/**
 * Drags the booking's chip with the mouse from the cell given onto another, by the browser's own
 * drag and drop, and lets it go near the target's top corner, where the day's date menu lies
 * under the pointer: a chip moves by whole days, so where in the cell it lands does not matter.
 */
function moveChipDrag(AwaitableWebpage|Webpage $page, string $uuid, string $from, string $target): void
{
    $playwright = movePlaywright($page);
    $playwright->locator($from.' .admin-calendar-chip[data-uuid="'.$uuid.'"]')->dragTo($playwright->locator($target), ['sourcePosition' => ['x' => 8, 'y' => 6], 'targetPosition' => ['x' => 12, 'y' => 8], 'steps' => 12]);
}

/**
 * Waits until the booking's chip is drawn once, in the cell given.
 *
 * @return Webpage
 */
function moveChipDrawn(AwaitableWebpage|Webpage $page, string $uuid, string $cell)
{
    $chip = '.admin-calendar-chip[data-uuid="'.$uuid.'"]';

    return $page->assertScript(<<<JS
        () => document.querySelectorAll('{$chip}').length === 1
            && document.querySelector('{$cell}')?.querySelector('{$chip}') !== null
        JS, true)
        ->assertCount($cell.' '.$chip, 1);
}

it('moves a booking by dragging its block to another time on Day, drawn once at the new time with the move on its trail', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    $date = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->format('d/m/Y');

    $page = moveDay($this, $day);
    moveDrag($page, $uuid, '10:00', moveColumn('Elena Papadaki', $day), '11:00');
    $page->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertSeeIn('.admin-move-confirm__from-to', $date.' 11:00')
        ->assertNotPresent('.admin-move-confirm__change')
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveDrawn($page, $uuid, moveColumn('Elena Papadaki', $day), '11:00')
        ->click('.fc-event[data-block-id="'.$uuid.':0"]')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-trail', adminLine('admin/bookings.trail.actions.rescheduled'))
        ->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('11:00');
});

it('gives the booking to another member by dragging its block onto their column on Day', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');

    $page = moveDay($this, $day);
    moveDrag($page, $uuid, '10:00', moveColumn('Nikos Georgiou', $day), '11:00');
    $page->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertSeeIn('.admin-move-confirm__change', adminLine('admin/bookings.move.confirm.member', ['service' => 'Haircut', 'from' => 'Elena Papadaki', 'to' => 'Nikos Georgiou']))
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveDrawn($page, $uuid, moveColumn('Nikos Georgiou', $day), '11:00')
        ->assertCount(moveColumn('Elena Papadaki', $day).' .fc-event[data-block-id="'.$uuid.':0"]', 0)
        ->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('11:00')
        ->and(moveStaffOf($uuid))->toBe('Nikos Georgiou');
});

it('refuses a drag onto another booking of the same member before any confirmation, says the server\'s sentence and puts the block back', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    moveBooking('Olly Over', $day, '11:00');

    $page = moveDay($this, $day);
    moveDrag($page, $uuid, '10:00', moveColumn('Elena Papadaki', $day), '11:00');
    $page->waitForText(siteSentence('api.envelope.overlap', SiteLanguage::English))
        ->assertNotPresent('.admin-move-confirm')
        ->assertSeeIn('.admin-toast', siteSentence('api.envelope.overlap', SiteLanguage::English));
    moveDrawn($page, $uuid, moveColumn('Elena Papadaki', $day), '10:00')->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('10:00');
});

it('leaves everything as it was on Keep it', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');

    $page = moveDay($this, $day);
    moveDrag($page, $uuid, '10:00', moveColumn('Nikos Georgiou', $day), '12:00');
    $page->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->click('.admin-move-confirm__keep')
        ->assertNotPresent('.admin-move-confirm');
    moveDrawn($page, $uuid, moveColumn('Elena Papadaki', $day), '10:00')
        ->assertCount(moveColumn('Nikos Georgiou', $day).' .fc-event[data-block-id="'.$uuid.':0"]', 0)
        ->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('10:00')
        ->and(moveStaffOf($uuid))->toBe('Elena Papadaki');
});

it('moves a booking to the next day at the same hour by dragging its chip along its member\'s row on Week', function () {
    $day = moveWednesday();
    $next = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->add(new DateInterval('P1D'))->format('Y-m-d');
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    $date = (new DateTimeImmutable($next, new DateTimeZone('Europe/Athens')))->format('d/m/Y');

    $page = moveDay($this, $day, 'week');
    moveChipDrag($page, $uuid, moveCell('Elena Papadaki', $day), moveCell('Elena Papadaki', $next));
    $page->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertSeeIn('.admin-move-confirm__from-to', $date.' 10:00')
        ->assertNotPresent('.admin-move-confirm__change')
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveChipDrawn($page, $uuid, moveCell('Elena Papadaki', $next))
        ->assertCount(moveCell('Elena Papadaki', $day).' .admin-calendar-chip', 0)
        ->assertNoJavaScriptErrors();

    expect(moveWhenOf($uuid))->toBe($next.' 10:00');
});

it('gives the booking to another member on another day by dragging its chip onto their row on Week, the hour kept', function () {
    $day = moveWednesday();
    $next = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->add(new DateInterval('P1D'))->format('Y-m-d');
    $uuid = moveBooking('Cora Cut', $day, '10:00');

    $page = moveDay($this, $day, 'week');
    moveChipDrag($page, $uuid, moveCell('Elena Papadaki', $day), moveCell('Nikos Georgiou', $next));
    $page->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertSeeIn('.admin-move-confirm__from-to', (new DateTimeImmutable($next, new DateTimeZone('Europe/Athens')))->format('d/m/Y').' 10:00')
        ->assertSeeIn('.admin-move-confirm__change', adminLine('admin/bookings.move.confirm.member', ['service' => 'Haircut', 'from' => 'Elena Papadaki', 'to' => 'Nikos Georgiou']))
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveChipDrawn($page, $uuid, moveCell('Nikos Georgiou', $next))->assertNoJavaScriptErrors();

    expect(moveWhenOf($uuid))->toBe($next.' 10:00')
        ->and(moveStaffOf($uuid))->toBe('Nikos Georgiou');
});

it('moves a booking to the next day at the same hour by dragging its chip on Month', function () {
    $day = moveWednesday();
    $next = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->add(new DateInterval('P1D'))->format('Y-m-d');
    $uuid = moveBooking('Cora Cut', $day, '10:00');

    $page = moveDay($this, $day, 'month');
    moveChipDrag($page, $uuid, moveMonthDay($day), moveMonthDay($next));
    $page->waitForText(adminLine('admin/bookings.move.confirm.title'))
        ->assertSeeIn('.admin-move-confirm__from-to', (new DateTimeImmutable($next, new DateTimeZone('Europe/Athens')))->format('d/m/Y').' 10:00')
        ->assertNotPresent('.admin-move-confirm__change')
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveChipDrawn($page, $uuid, moveMonthDay($next))->assertNoJavaScriptErrors();

    expect(moveWhenOf($uuid))->toBe($next.' 10:00')
        ->and(moveStaffOf($uuid))->toBe('Elena Papadaki');
});

it('warns before a drag onto a closure on Day, puts the block back on Cancel, and moves it onto another member\'s closure on Move anyway', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    moveClosure(null, $day, '11:00', '12:00', 'Holiday');
    moveClosure('Nikos Georgiou', $day, '15:00', '16:00', 'Dentist');

    $page = moveDay($this, $day);
    moveDrag($page, $uuid, '10:00', moveColumn('Elena Papadaki', $day), '11:00');
    $page->waitForText(adminLine('admin/bookings.move.confirm.closed_title'))
        ->assertSeeIn('.admin-move-confirm__booking', adminLine('admin/bookings.move.confirm.booking', ['customer' => 'Cora Cut', 'services' => 'Haircut']))
        ->assertSeeIn('.admin-move-confirm__warning', adminLine('admin/bookings.move.confirm.business_closed_reason', ['when' => moveOn($day, '11:00', '12:00'), 'reason' => 'Holiday']))
        ->assertSeeIn('.admin-move-confirm__keep', adminLine('admin/bookings.move.confirm.cancel'))
        ->click('.admin-move-confirm__keep')
        ->assertNotPresent('.admin-move-confirm');
    moveDrawn($page, $uuid, moveColumn('Elena Papadaki', $day), '10:00');

    expect(moveStartOf($uuid))->toBe('10:00');

    moveDrag($page, $uuid, '10:00', moveColumn('Nikos Georgiou', $day), '15:00');
    $page->waitForText(adminLine('admin/bookings.move.confirm.closed_title'))
        ->assertSeeIn('.admin-move-confirm__change', adminLine('admin/bookings.move.confirm.member', ['service' => 'Haircut', 'from' => 'Elena Papadaki', 'to' => 'Nikos Georgiou']))
        ->assertSeeIn('.admin-move-confirm__warning', adminLine('admin/bookings.move.confirm.staff_closed_reason', ['staff' => 'Nikos Georgiou', 'when' => moveOn($day, '15:00', '16:00'), 'reason' => 'Dentist']))
        ->assertSeeIn('.admin-move-confirm__confirm', adminLine('admin/bookings.move.confirm.move_anyway'))
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveDrawn($page, $uuid, moveColumn('Nikos Georgiou', $day), '15:00')->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('15:00')
        ->and(moveStaffOf($uuid))->toBe('Nikos Georgiou');
});

it('warns before a drag onto the member\'s day off on Week, leaves the chip where it was on Cancel, and moves it there on Move anyway', function () {
    $day = moveWednesday();
    $saturday = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->add(new DateInterval('P3D'))->format('Y-m-d');
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    $wednesdays = moveCell('Elena Papadaki', $day);
    $saturdays = moveCell('Elena Papadaki', $saturday);
    $off = adminLine('admin/bookings.move.confirm.off_weekday', ['staff' => 'Elena Papadaki', 'weekdays' => adminLine('admin/bookings.move.confirm.weekdays.saturday')]);

    $page = moveDay($this, $day, 'week')->assertPresent($saturdays.'.admin-calendar-week__cell--hatched');
    moveChipDrag($page, $uuid, $wednesdays, $saturdays);
    $page->waitForText(adminLine('admin/bookings.move.confirm.closed_title'))
        ->assertSeeIn('.admin-move-confirm__from-to', (new DateTimeImmutable($saturday, new DateTimeZone('Europe/Athens')))->format('d/m/Y').' 10:00')
        ->assertSeeIn('.admin-move-confirm__warning', $off)
        ->click('.admin-move-confirm__keep')
        ->assertNotPresent('.admin-move-confirm');
    moveChipDrawn($page, $uuid, $wednesdays);

    expect(moveWhenOf($uuid))->toBe($day.' 10:00');

    moveChipDrag($page, $uuid, $wednesdays, $saturdays);
    $page->waitForText(adminLine('admin/bookings.move.confirm.closed_title'))
        ->assertSeeIn('.admin-move-confirm__warning', $off)
        ->click('.admin-move-confirm__confirm')
        ->waitForText(adminLine('admin/bookings.flashes.moved'));
    moveChipDrawn($page, $uuid, $saturdays)->assertNoJavaScriptErrors();

    expect(moveWhenOf($uuid))->toBe($saturday.' 10:00');
});

it('says the closed-time warning to an owner who reads Greek as a sentence, the day behind its article and its hours behind a comma', function () {
    $day = moveWednesday();
    $uuid = moveBooking('Cora Cut', $day, '10:00');
    moveClosure(null, $day, '11:00', '12:00', 'Holiday');
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $el = SiteLanguage::Greek;
    $date = (new DateTimeImmutable($day, new DateTimeZone('Europe/Athens')))->format('d/m/Y');
    $named = adminLine('admin/bookings.move.confirm.days.wednesday', ['date' => $date], $el);
    $when = adminLine('admin/bookings.move.confirm.on_day_window', ['day' => $named, 'start' => '11:00', 'end' => '12:00'], $el);

    $page = moveDay($this, $day);
    moveDrag($page, $uuid, '10:00', moveColumn('Elena Papadaki', $day), '11:00');
    $page->waitForText(adminLine('admin/bookings.move.confirm.closed_title', [], $el))
        ->assertSeeIn('.admin-move-confirm__warning', adminLine('admin/bookings.move.confirm.business_closed_reason', ['when' => $when, 'reason' => 'Holiday'], $el))
        // The day as a Greek reader says it: the article, the weekday, the date, then the hours.
        ->assertSeeIn('.admin-move-confirm__warning', "\u{03C4}\u{03B7}\u{03BD} \u{03A4}\u{03B5}\u{03C4}\u{03AC}\u{03C1}\u{03C4}\u{03B7} ".$date.', 11:00 ')
        ->click('.admin-move-confirm__keep')
        ->assertNotPresent('.admin-move-confirm')
        ->assertNoJavaScriptErrors();

    expect(moveStartOf($uuid))->toBe('10:00');
});
