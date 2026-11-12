<?php

use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Support\StartInterval;

/*
 * The when step on real data: on luna, the haircut both people perform with no preference reaches
 * the step, whose week row holds the seven days from luna's today with the first day holding times
 * selected; a Monday in the seeded hours, a week on, shows Elena's times inside her hours and none
 * inside her lunch gap, since Nikos works Tuesday to Saturday, behind the three periods of the day
 * (when.period); every time reads as the tenant's hour; picking one lands on the details step with
 * focus on the flow container. At the default start interval the same Monday offers the quarter
 * hours alone. The bundle is the one npm run build wrote, which is why the build gate precedes this
 * one.
 */

/**
 * Whether the grid shows some times and every one of them falls on a quarter hour of luna's
 * wall clock, read in the page by the tenant's zone rather than from the UTC the value carries.
 */
const WHEN_ON_THE_QUARTER_HOURS = <<<'JS'
    (() => {
        const slots = Array.from(document.querySelectorAll('.kr-booking__slot'));
        const clock = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Athens', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

        return slots.length > 0 && slots.every((slot) => ['00', '15', '30', '45'].includes(clock.format(new Date(slot.value)).slice(-2)));
    })()
    JS;

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * Every wall-clock reading from one to another inclusive on a day in Athens, five minutes
 * apart unless another step is given, spelled as the wire spells a start: RFC 3339 in UTC.
 *
 * @return list<string>
 */
function lunaEvery(DateTimeImmutable $day, string $from, string $to, int $step = 5): array
{
    $utc = new DateTimeZone('UTC');
    [$fromHour, $fromMinute] = array_map(intval(...), explode(':', $from));
    [$toHour, $toMinute] = array_map(intval(...), explode(':', $to));
    $end = $day->setTime($toHour, $toMinute);
    $starts = [];

    for ($at = $day->setTime($fromHour, $fromMinute); $at <= $end; $at = $at->add(new DateInterval(sprintf('PT%dM', $step)))) {
        $starts[] = $at->setTimezone($utc)->format(DateTimeImmutable::RFC3339);
    }

    return $starts;
}

it('shows the seeded hours on a Monday for the haircut with no preference, none inside the lunch gap, and lands on details on a pick', function () {
    // The subject is the engine's five-minute grid, so luna offers every start it finds free.
    StartInterval::set('tenant_luna', 5);

    $today = athensToday();
    $monday = firstWindowDay(1);
    $morning = lunaEvery($monday, '09:00', '11:55');
    $afternoon = [...lunaEvery($monday, '12:00', '12:15'), ...lunaEvery($monday, '14:00', '16:55')];
    $evening = lunaEvery($monday, '17:00', '17:15');
    $picked = lunaEvery($monday, '14:00', '14:00')[0];
    // Each period as PERIODS reads it: its name, its count of times and whether it is chosen.
    $period = fn (string $key, int $count, string $chosen): string => sprintf(
        '%s %s:%s',
        Flow::said($key),
        trans_choice('booking.when.times', $count, [], 'en'),
        $key === $chosen ? 'true' : 'false',
    );
    $periods = fn (string $chosen): string => implode('|', [
        $period('when.period.morning', count($morning), $chosen),
        $period('when.period.afternoon', count($afternoon), $chosen),
        $period('when.period.evening', count($evening), $chosen),
    ]);
    $mondayButton = sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d'));

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->assertScript(FlowScripts::CHOICES, sprintf('%s %s:true|Elena Papadaki:false|Nikos Georgiou:false', Flow::said('staff.any'), Flow::said('staff.any_line')))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertScript(FlowScripts::daysRendered(7), true)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertSeeIn('.kr-booking__timezone', 'Europe/Athens')
        ->assertScript("document.querySelector('.kr-booking__day').value", $today->format('Y-m-d'))
        ->assertScript("document.querySelectorAll('.kr-booking__day').length", 7)
        ->assertScript("(() => { const days = Array.from(document.querySelectorAll('.kr-booking__day')); const open = days.find((day) => !day.disabled); return open !== undefined && days.filter((day) => day.getAttribute('aria-pressed') === 'true').map((day) => day.value).join('|') === open.value; })()", true)
        ->assertScript("document.querySelectorAll('.kr-booking__slot').length > 0", true)
        ->assertScript("document.querySelectorAll('.kr-booking__previous').length", 0)
        ->assertDontSee(Flow::said('when.hint'));

    Flow::weekOf($page, $monday)
        ->click($mondayButton)
        ->assertScript(FlowScripts::PRESSED_DAYS, $monday->format('Y-m-d'))
        ->assertScript(FlowScripts::PERIODS, $periods('when.period.morning'))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $morning))
        ->assertScript("document.querySelector('.kr-booking__slot').textContent", '9:00 AM')
        ->click(Flow::said('when.period.evening'))
        ->assertScript(FlowScripts::PERIODS, $periods('when.period.evening'))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $evening))
        ->click(Flow::said('when.period.afternoon'))
        ->assertScript(FlowScripts::PERIODS, $periods('when.period.afternoon'))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $afternoon))
        ->assertScript("Array.from(document.querySelectorAll('.kr-booking__slot')).some((slot) => /^1:|^13:/.test(slot.textContent))", false)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $picked))
        ->assertScript(FlowScripts::STEP, 'details')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertScript("document.querySelectorAll('.kr-booking__slot').length", 0)
        ->click('.kr-booking__back')
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::PRESSED_DAYS, $monday->format('Y-m-d'))
        ->assertScript(FlowScripts::PERIODS, $periods('when.period.afternoon'))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $afternoon))
        ->assertNoJavaScriptErrors();
});

it('moves the window by seven whole days and never before today', function () {
    $today = athensToday();
    $next = $today->add(new DateInterval('P7D'));
    $sundayButton = sprintf('.kr-booking__day[value="%s"]', firstWindowDay(7)->format('Y-m-d'));

    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Beard trim')
        ->click(Flow::said('estimate.continue'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::daysRendered(7), true)
        ->assertScript("document.querySelector('.kr-booking__next').textContent", Flow::said('when.next_week'))
        ->click('.kr-booking__next')
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript("document.querySelector('.kr-booking__day').value", $next->format('Y-m-d'))
        ->assertScript("document.querySelectorAll('.kr-booking__day').length", 7)
        ->assertScript(sprintf("document.querySelector('%s').disabled", $sundayButton), true)
        ->assertScript(sprintf("document.querySelector('%s').getAttribute('aria-label').endsWith(%s)", $sundayButton, json_encode(Flow::said('when.closed_day', ['date' => '']), JSON_THROW_ON_ERROR)), true)
        ->assertScript("document.querySelector('.kr-booking__previous').textContent", Flow::said('when.previous_week'))
        ->click('.kr-booking__previous')
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript("document.querySelector('.kr-booking__day').value", $today->format('Y-m-d'))
        ->assertScript("document.querySelectorAll('.kr-booking__previous').length", 0)
        ->assertScript("document.activeElement === document.querySelector('.kr-booking__next')")
        ->assertNoJavaScriptErrors();
});

it('offers the quarter hours alone at the default start interval, leaving out starts the five-minute grid finds free', function () {
    $monday = firstWindowDay(1);
    $morning = lunaEvery($monday, '09:00', '11:45', 15);
    $afternoon = [...lunaEvery($monday, '12:00', '12:15', 15), ...lunaEvery($monday, '14:00', '16:45', 15)];
    $evening = lunaEvery($monday, '17:00', '17:15', 15);

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::PERIODS, sprintf(
            '%s %s:true|%s %s:false|%s %s:false',
            Flow::said('when.period.morning'),
            trans_choice('booking.when.times', count($morning), [], 'en'),
            Flow::said('when.period.afternoon'),
            trans_choice('booking.when.times', count($afternoon), [], 'en'),
            Flow::said('when.period.evening'),
            trans_choice('booking.when.times', count($evening), [], 'en'),
        ))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $morning))
        ->assertScript(WHEN_ON_THE_QUARTER_HOURS, true)
        ->assertCount(sprintf('.kr-booking__slot[value="%s"]', lunaEvery($monday, '09:05', '09:05')[0]), 0)
        ->click(Flow::said('when.period.afternoon'))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $afternoon))
        ->assertScript(WHEN_ON_THE_QUARTER_HOURS, true)
        ->click(Flow::said('when.period.evening'))
        ->assertScript(FlowScripts::SLOT_VALUES, implode('|', $evening))
        ->assertScript(WHEN_ON_THE_QUARTER_HOURS, true)
        ->assertNoJavaScriptErrors();
});
