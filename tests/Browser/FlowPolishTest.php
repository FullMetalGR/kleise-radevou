<?php

use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * The flow's polish on real data: luna closed for the whole of its first week, so the when step
 * opens on a week whose every day is empty and the public read names the next day holding a start.
 * A customer books through the crumb of the steps and the jump to the next available date
 * (when.next_available): the crumb takes them back to a finished step and on again with the chain
 * and what they typed intact, focus landing on the flow container each time; the button moves the
 * window to the week holding that day and selects it; the summary card, there from the service step
 * on, stands sticky beside the step where the frame is wide enough for both and under it where it
 * is not, carries the one button that continues, and is the sticky bottom bar at a phone's width;
 * the review shows the server's summary and neither the crumb nor the card. The browser runs on the
 * system clock, so every date here is reckoned from luna's today.
 */

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * Scrolls the flow's frame to the top of the screen, so what follows reads the flow running on
 * below it rather than wherever the last press left the page.
 */
const POLISH_FRAME_ON_TOP = "(() => { document.querySelector('.kr-booking__frame').scrollIntoView({ block: 'start' }); return true; })()";

/**
 * Closes luna, tenant-wide, on its today and the six days after it: the week row's first week.
 */
function polishCloseFirstWeek(): void
{
    $today = athensToday();

    foreach (range(0, 6) as $days) {
        DB::table('tenant_luna.blackouts')->insert(['staff_id' => null, 'date' => $today->add(new DateInterval(sprintf('P%dD', $days)))->format('Y-m-d'), 'reason' => 'Closed for a refit']);
    }
}

/**
 * The first day after luna's first week on which somebody performs the haircut: a weekday
 * neither Sunday, when nobody works, nor a day a tenant-wide blackout closes. Looked for over the
 * fourteen days after the first week and no further, so a fixture closing them all fails here by
 * name rather than looping.
 */
function polishNextOpenDay(): DateTimeImmutable
{
    foreach (range(7, 20) as $days) {
        $day = athensToday()->add(new DateInterval(sprintf('P%dD', $days)));

        if ((int) $day->format('N') !== 7 && ! DB::table('tenant_luna.blackouts')->whereNull('staff_id')->where('date', $day->format('Y-m-d'))->exists()) {
            return $day;
        }
    }

    throw new LogicException('Luna has no open day in the fourteen days after its first week.');
}

it('books on a tenant whose first week is empty through the strip and the jump to the next available date, the running summary beside the flow and a sticky bar at a phone\'s width', function () {
    polishCloseFirstWeek();
    $open = polishNextOpenDay();
    $start = Flow::start($open, 10);
    $crumbs = "Array.from(document.querySelectorAll('.kr-booking__crumb')).map((crumb) => crumb.value + ':' + (crumb.getAttribute('aria-current') === 'step' ? 'current' : crumb.disabled ? 'disabled' : 'live')).join('|')";
    // Beside the step, sticky, where the frame holds the step's 480px, the gap's 40 and the card's
    // 360; under the step where it does not.
    $beside = "(() => { const frame = document.querySelector('.kr-booking__frame').getBoundingClientRect(); const main = document.querySelector('.kr-booking__main').getBoundingClientRect(); const summary = document.querySelector('.kr-booking__estimate'); const box = summary.getBoundingClientRect(); return getComputedStyle(summary).position === 'sticky' && (frame.width >= 880 ? box.left >= main.right : box.top >= main.bottom); })()";
    // Stuck: the summary's foot sits on the screen's while the flow runs on below it, nothing
    // covers it there, and the page's own Book now bar has given way to it. Read on the service
    // step with a service chosen, on a phone's width and a screen short enough that the cards run
    // on below it once the frame's top is scrolled to the screen's. The bar is the card's compact
    // form: its lines and its head hidden, the sum and the brief count shown beside the button
    // that continues.
    $brief = trans_choice('booking.estimate.brief', 1, ['duration' => Flow::said('duration.minutes', ['count' => '45'])], 'en');
    $bar = "(() => { const summary = document.querySelector('.kr-booking__estimate'); const style = getComputedStyle(summary); const box = summary.getBoundingClientRect(); const under = document.elementFromPoint(window.innerWidth / 2, window.innerHeight - 4); const hidden = (selector) => getComputedStyle(summary.querySelector(selector)).display === 'none'; const shown = (selector) => getComputedStyle(summary.querySelector(selector)).display !== 'none'; return style.position === 'sticky' && style.bottom === '0px' && Math.abs(box.bottom - window.innerHeight) < 1 && document.querySelector('.kr-booking__main').getBoundingClientRect().bottom > window.innerHeight && summary.contains(under) && getComputedStyle(document.querySelector('.hosted-bar')).display === 'none' && hidden('.kr-booking__estimate-head') && hidden('.kr-booking__estimate-items') && hidden('.kr-booking__estimate-label') && shown('.kr-booking__estimate-sum') && shown('.kr-booking__estimate-brief') && summary.querySelector('.kr-booking__estimate-brief').textContent === ".json_encode($brief, JSON_THROW_ON_ERROR)." && shown('.kr-booking__continue'); })()";
    // Before the first service the card is hidden at a phone's width and the page's own Book now
    // bar keeps the bottom edge; the hosted page's rule for that bar is the business page's own.
    // Read as a wait, since the resize lands a frame after the call.
    $hiddenBar = "new Promise((resolve) => { const started = Date.now(); const check = () => { const summary = document.querySelector('.kr-booking__estimate'); if (summary.classList.contains('kr-booking__estimate--empty') && getComputedStyle(summary).display === 'none') { resolve(true); } else if (Date.now() - started > 5000) { resolve(false); } else { setTimeout(check, 25); } }; check(); })";

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertSeeIn('.kr-booking__estimate-empty', Flow::said('estimate.pick_a_service'))
        ->assertScript(FlowScripts::CONTINUE_DISABLED, true)
        ->assertScript($beside, true)
        ->resize(390, 700)
        ->assertScript($hiddenBar, true)
        ->resize(1280, 800)
        ->click('Haircut')
        ->assertSeeIn('.kr-booking__estimate-label', Flow::said('estimate.label'))
        ->assertSeeIn('.kr-booking__estimate-name', 'Haircut')
        ->assertSeeIn('.kr-booking__estimate-detail', '45 min')
        ->assertSeeIn('.kr-booking__estimate-sum', '35.00')
        ->assertSeeIn('.kr-booking__estimate-duration', '45 min')
        ->resize(390, 700)
        ->assertScript(POLISH_FRAME_ON_TOP, true)
        ->assertScript($bar, true)
        ->resize(1280, 800)
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->assertScript($crumbs, 'service:live|staff:current|when:disabled|details:disabled')
        ->click('.kr-booking__crumb[value="service"]')
        ->assertScript(FlowScripts::STEP, 'service')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertSeeIn('.kr-booking__estimate-name', 'Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::daysRendered(7), true)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript("document.querySelector('.kr-booking__day').value", athensToday()->format('Y-m-d'))
        ->assertScript("Array.from(document.querySelectorAll('.kr-booking__day')).every((day) => day.disabled)", true)
        ->assertSee(Flow::said('when.hint'))
        ->assertScript("document.querySelector('.kr-booking__jump').value", $open->format('Y-m-d'))
        ->assertSeeIn('.kr-booking__jump', Flow::said('when.next_available'))
        ->assertScript($beside, true)
        ->click('.kr-booking__jump')
        ->assertScript(FlowScripts::dayRendered($open), true)
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertScript(FlowScripts::PRESSED_DAYS, $open->format('Y-m-d'))
        ->assertScript("document.querySelectorAll('.kr-booking__jump').length", 0)
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') !== null", $start), true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertScript($crumbs, 'service:live|staff:live|when:live|details:current')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->assertScript("document.querySelectorAll('.kr-booking__crumb-answer').length", 0)
        ->assertSeeIn('.kr-booking__estimate-detail', trim(Flow::said('estimate.any', ['duration' => ''])))
        ->assertScript("document.querySelector('.kr-booking__estimate-when').textContent.length > 0", true)
        ->click('.kr-booking__crumb[value="when"]')
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertScript(FlowScripts::PRESSED_DAYS, $open->format('Y-m-d'))
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertValue('customer_name', 'Casey Customer')
        ->assertValue('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript("document.querySelectorAll('.kr-booking__crumbs, .kr-booking__estimate').length", 0)
        ->assertSeeIn('.kr-booking__summary-service', 'Haircut')
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertScript("document.querySelectorAll('.kr-booking__crumbs, .kr-booking__estimate').length", 0)
        ->assertNoJavaScriptErrors();

    $booked = DB::scalar('select start_utc::text from tenant_luna.booking_items');
    assert(is_string($booked));

    expect(DB::table('tenant_luna.bookings')->value('status'))->toBe('confirmed')
        ->and(DB::table('tenant_luna.booking_items')->count())->toBe(1)
        ->and((new DateTimeImmutable($booked, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339))->toBe($start);
});

/**
 * How many holds the page has requested: the details form's submit is the one place a hold is
 * created, so a submit the browser's own validation stopped leaves this at nought.
 */
const POLISH_HOLDS_REQUESTED = "performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname === '/api/v1/holds').length";

it('submits the details form through the summary card\'s button that continues: Enter and the press both stop on an empty required field with no hold, and Enter alone reaches review once the fields are filled', function () {
    $monday = Flow::day('Europe/Athens', 1);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', Flow::start($monday, 10)))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertScript("document.querySelector('.kr-booking__continue').getAttribute('form') === document.querySelector('.kr-booking__form').id", true)
        ->assertScript("document.querySelector('.kr-booking__form button') === null", true)
        ->type('customer_name', 'Casey Customer')
        ->keys('customer_name', 'Enter')
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertScript(POLISH_HOLDS_REQUESTED, 0)
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertScript(POLISH_HOLDS_REQUESTED, 0)
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertValue('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->keys('customer_email', 'Enter')
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertScript(POLISH_HOLDS_REQUESTED, 1)
        ->assertSeeIn('.kr-booking__summary-service', 'Haircut')
        ->assertNoJavaScriptErrors();
});
