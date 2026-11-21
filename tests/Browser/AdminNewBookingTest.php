<?php

use App\Http\Controllers\Admin\NewBookingController;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;
use Tests\Support\LunaFixture;

/*
 * The manual booking wizard in a real browser: from the top bar, an owner books two services for
 * a customer who called, on a chosen day and time, sees the toast, finds the booking confirmed
 * in the list, opens it and sees the trail naming the operator.
 *
 * The day is the first Tuesday at least a week ahead of the machine's clock, when Elena works
 * 09:00 to 13:00 and 14:00 to 18:00 on luna, so the first slot the morning group offers is 09:00
 * whatever day the suite runs on; the day calendar opens on the tenant's today and is walked
 * forward a month at a time until that Tuesday is on it. 09:05 is offered beside it at luna's
 * default start interval of fifteen minutes, since the wizard's read offers every start.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/**
 * The Tuesday of the second availability window, in Athens, firstWindowDay's, how many months
 * ahead of today it lies, and its 09:00 and 09:05 as the wire spells them.
 *
 * @return array{date: string, months: int, label: string, nine: string, ninePastFive: string}
 */
function wizardTuesday(): array
{
    $today = athensToday();
    $day = firstWindowDay(2);
    $months = ((int) $day->format('Y') - (int) $today->format('Y')) * 12 + (int) $day->format('n') - (int) $today->format('n');
    $nine = $day->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);
    $ninePastFive = $day->setTime(9, 5)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);

    return ['date' => $day->format('Y-m-d'), 'months' => $months, 'label' => $day->format('d/m/Y'), 'nine' => $nine, 'ninePastFive' => $ninePastFive];
}

it('books two services for a customer from the top bar, sees the toast, finds the booking confirmed in the list and its trail naming the operator', function () {
    ['date' => $date, 'months' => $months, 'label' => $label, 'nine' => $nine, 'ninePastFive' => $ninePastFive] = wizardTuesday();
    $colour = LunaFixture::id('services', 'Colour');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('bookings'))->waitForText(adminLine('admin/bookings.page.empty'));

    $page->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->assertSeeIn('.admin-wizard-strip', 'Haircut')
        ->click('.admin-wizard-add')
        ->waitForText(adminLine('admin/customers.wizard.segment', ['n' => '2']))
        ->select('#wizard-service-1', (string) $colour)
        ->assertSeeIn('.admin-wizard-strip', adminChoice('admin/customers.wizard.first_more', 1, ['service' => 'Haircut']))
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens']));
    Look::shot($page, 'booking-wizard-1728');

    for ($month = 0; $month < $months; $month++) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click('button[data-date="'.$date.'"]')
        ->waitForText(adminLine('admin/customers.wizard.periods.morning'))
        ->assertSeeIn('.admin-wizard-strip', $label)
        ->assertCount('.admin-wizard-slot[value="'.$ninePastFive.'"]', 1)
        ->click('.admin-wizard-slot[value="'.$nine.'"]')
        ->assertSeeIn('.admin-wizard-strip', adminLine('admin/customers.wizard.when', ['date' => $label, 'time' => '09:00']))
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.fields.name'))
        ->assertSeeIn('.admin-wizard-summary', adminLine('admin/customers.wizard.summary.span', ['time' => '09:00', 'end' => '10:45']))
        ->assertSeeIn('.admin-wizard-summary', adminLine('admin/customers.wizard.summary.service_staff', ['service' => 'Haircut', 'staff' => adminLine('admin/customers.wizard.any_staff')]))
        ->assertSeeIn('.admin-wizard-summary', adminLine('admin/customers.wizard.summary.service_staff', ['service' => 'Colour', 'staff' => adminLine('admin/customers.wizard.any_staff')]))
        ->type('#wizard-customer-name', 'Wanda Walkin')
        ->type('#wizard-customer-email', 'wanda@example.test')
        ->click('.admin-wizard-create')
        ->waitForText(adminLine(NewBookingController::CREATED))
        ->waitForText('Wanda Walkin')
        ->assertCount('.admin-wizard', 0);

    $page->click('Wanda Walkin')
        ->waitForText(adminLine('admin/bookings.dialog.items.title'))
        ->assertSeeIn('.admin-booking-status .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
        ->assertCount('.admin-booking-items__row', 2)
        ->assertCount('.admin-booking-trail__entry', 1)
        ->assertSeeIn('.admin-booking-trail__entry', 'Demo Owner');

    expect(DB::table('tenant_luna.bookings')->where('customer_name', 'Wanda Walkin')->value('status'))->toBe('confirmed');
});

/**
 * True when the strip's three steps share one line inside it, each step no wider than its share
 * of the line, the current one holding its words without spilling and each other one narrowed to
 * its 52 pixel number pill.
 */
const NEW_BOOKING_STRIP_ON_PHONE = <<<'JS'
    document.fonts.ready.then(() => {
        const strip = document.querySelector('.admin-wizard-strip');
        const steps = Array.from(document.querySelectorAll('.admin-wizard-strip .admin-wizard-step'));
        const current = steps.findIndex((step) => step.getAttribute('aria-current') === 'step');
        if (strip === null || steps.length !== 3 || current < 0) {
            return false;
        }
        const room = strip.getBoundingClientRect();
        const boxes = steps.map((step) => step.getBoundingClientRect());
        return boxes.every((box) => Math.abs(box.top - boxes[0].top) <= 1 && box.left >= room.left - 1 && box.right <= room.right + 1)
            && boxes.every((box, at) => at === current || Math.abs(box.width - 52) <= 1)
            && steps[current].scrollWidth <= steps[current].clientWidth + 1;
    })
    JS;

/** True when the strip's three steps share one line at one width, side by side. */
const NEW_BOOKING_STRIP_IN_THIRDS = <<<'JS'
    document.fonts.ready.then(() => {
        const boxes = Array.from(document.querySelectorAll('.admin-wizard-strip .admin-wizard-step')).map((step) => step.getBoundingClientRect());
        return boxes.length === 3
            && boxes.every((box) => Math.abs(box.top - boxes[0].top) <= 1 && Math.abs(box.width - boxes[0].width) <= 1)
            && boxes.every((box, at) => at === 0 || box.left >= boxes[at - 1].right);
    })
    JS;

/**
 * True when the first group's slots are 44 pixels high and three to a line, the fourth starting
 * the next line, all under the day calendar.
 */
const NEW_BOOKING_SLOTS_ON_PHONE = <<<'JS'
    document.fonts.ready.then(() => {
        const grid = document.querySelector('.admin-wizard-grid');
        const group = document.querySelector('.admin-wizard-group');
        const boxes = group === null ? [] : Array.from(group.querySelectorAll('.admin-wizard-slot')).map((slot) => slot.getBoundingClientRect());
        if (grid === null || boxes.length < 4) {
            return false;
        }
        const first = boxes.filter((box) => Math.abs(box.top - boxes[0].top) <= 1);
        return first.length === 3
            && boxes[3].top >= boxes[0].bottom
            && boxes.every((box) => Math.abs(box.height - 44) <= 0.5)
            && boxes[0].top >= grid.getBoundingClientRect().bottom;
    })
    JS;

/** True when the day calendar stands to the left of the day's times, on the same rows. */
const NEW_BOOKING_DAY_BESIDE_TIMES = <<<'JS'
    document.fonts.ready.then(() => {
        const grid = document.querySelector('.admin-wizard-grid')?.getBoundingClientRect();
        const times = document.querySelector('.admin-wizard-times')?.getBoundingClientRect();
        return grid !== undefined && times !== undefined && times.left >= grid.right && times.top < grid.bottom && grid.top < times.bottom;
    })
    JS;

/**
 * Where the summary stands against the customer fields: "beside" when it is to their right on
 * the same rows, "over" when it stands above them.
 */
const NEW_BOOKING_SUMMARY_PLACE = <<<'JS'
    document.fonts.ready.then(() => {
        const summary = document.querySelector('.admin-wizard-summary')?.getBoundingClientRect();
        const name = document.querySelector('#wizard-customer-name')?.getBoundingClientRect();
        if (summary === undefined || name === undefined) {
            return 'missing';
        }
        if (summary.left >= name.right && summary.top < name.bottom && name.top < summary.bottom) {
            return 'beside';
        }
        return name.top >= summary.bottom ? 'over' : 'elsewhere';
    })
    JS;

/** The wizard's frame's width in whole pixels. */
const NEW_BOOKING_FRAME_WIDTH = "Math.round(document.querySelector('.admin-wizard').getBoundingClientRect().width)";

/** True when each of the strip's three number circles is 30 pixels wide and 30 high. */
const NEW_BOOKING_NUMBER_CIRCLES = <<<'JS'
    document.fonts.ready.then(() => {
        const boxes = Array.from(document.querySelectorAll('.admin-wizard-strip .admin-wizard-step__number')).map((circle) => circle.getBoundingClientRect());
        return boxes.length === 3 && boxes.every((box) => Math.abs(box.width - 30) <= 0.5 && Math.abs(box.height - 30) <= 0.5);
    })
    JS;

/** Scrolls the dialog's body to its end, where the row under the service cards holds the same-staff tick. */
const NEW_BOOKING_SHOW_SAME_STAFF = "document.querySelector('.admin-modal__body').scrollTo(0, document.querySelector('.admin-modal__body').scrollHeight)";

/** The strip's first step, a live button once it is finished. */
const NEW_BOOKING_FIRST_STEP = '.admin-wizard-strip li:nth-child(1) .admin-wizard-step';

/**
 * Puts focus on the strip's first step and says whether it shows the keyboard's outline, which
 * a focus set by the page does only while the keyboard was the last thing used.
 */
const NEW_BOOKING_FOCUS_FIRST_STEP = <<<'JS'
    (() => {
        const step = document.querySelector('.admin-wizard-strip li:nth-child(1) .admin-wizard-step');
        step.focus();
        return step.matches(':focus-visible');
    })()
    JS;

/**
 * How the keyboard's outline on the strip's first step stands against the top of the dialog's
 * scrolling body, which cuts whatever is drawn over that edge: "clear" when the room above the
 * step holds the outline's width and its offset together, otherwise the two figures.
 */
const NEW_BOOKING_FOCUS_OUTLINE = <<<'JS'
    document.fonts.ready.then(() => {
        const step = document.querySelector('.admin-wizard-strip li:nth-child(1) .admin-wizard-step');
        const body = document.querySelector('.admin-modal__body');
        if (step === null || body === null || !step.matches(':focus-visible')) {
            return 'the first step does not hold the keyboard focus';
        }
        const style = getComputedStyle(step);
        const reach = parseFloat(style.outlineWidth) + parseFloat(style.outlineOffset);
        const room = step.getBoundingClientRect().top - body.getBoundingClientRect().top - body.clientTop;
        return style.outlineStyle === 'solid' && reach > 0 && room >= reach ? 'clear' : `room ${room} for an outline reaching ${reach}`;
    })
    JS;

/** True when no line of the summary is wider than the summary, so every word stays inside its card. */
const NEW_BOOKING_SUMMARY_HOLDS_ITS_WORDS = <<<'JS'
    document.fonts.ready.then(() => {
        const lines = Array.from(document.querySelectorAll('.admin-wizard-summary dd'));
        return lines.length === 3 && lines.every((line) => line.scrollWidth <= line.clientWidth + 1);
    })
    JS;

it('fits each step to a phone, the strip on one line with the current step\'s words alone, the slots three to a line under the day calendar and the summary over the fields, nothing scrolling sideways', function (SiteLanguage $language) {
    ['date' => $date, 'months' => $months, 'nine' => $nine] = wizardTuesday();
    // The language is the owner's before the sign-in, so the whole walk reads it.
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment', [], $language))
        ->assertScript(PhoneScripts::PAGE_LANGUAGE, $language->value)
        ->assertScript(NEW_BOOKING_STRIP_ON_PHONE, true)
        ->assertScript(NEW_BOOKING_NUMBER_CIRCLES, true)
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'new-booking-1-390-'.$language->value);

    // A second service brings the same-staff tick onto the row under the cards; it is taken
    // away again once read, so the rest of the walk books the one service.
    $page->click('.admin-wizard-add')
        ->waitForText(adminLine('admin/customers.wizard.same_staff', [], $language))
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    $page->script(NEW_BOOKING_SHOW_SAME_STAFF);
    Look::shot($page, 'new-booking-1b-390-'.$language->value);

    $page->click('.admin-wizard-services > :nth-child(2) .admin-wizard-remove')
        ->assertNotPresent('.admin-wizard-same-staff');

    $page->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens'], $language));

    for ($month = 0; $month < $months; $month++) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next', [], $language)));
    }

    $page->click('button[data-date="'.$date.'"]')
        ->waitForText(adminLine('admin/customers.wizard.periods.morning', [], $language))
        ->assertScript(NEW_BOOKING_STRIP_ON_PHONE, true)
        ->assertScript(PhoneScripts::WIZARD_DAY_OVER_TIMES, true)
        ->assertScript(NEW_BOOKING_SLOTS_ON_PHONE, true)
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'new-booking-2-390-'.$language->value);

    $page->click('.admin-wizard-slot[value="'.$nine.'"]')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.fields.name', [], $language))
        ->assertScript(NEW_BOOKING_STRIP_ON_PHONE, true)
        ->assertScript(NEW_BOOKING_NUMBER_CIRCLES, true)
        ->assertScript(NEW_BOOKING_SUMMARY_PLACE, 'over')
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'new-booking-3-390-'.$language->value);

    $page->click('.admin-wizard-cancel')
        ->assertNotPresent('.admin-wizard')
        ->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('lays each step out on a wide screen, the dialog 1000 pixels wide with the strip in thirds, the day calendar beside the slots and the summary beside the customer fields', function () {
    ['date' => $date, 'months' => $months, 'nine' => $nine] = wizardTuesday();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->assertScript(NEW_BOOKING_FRAME_WIDTH, 1000)
        ->assertScript(NEW_BOOKING_STRIP_IN_THIRDS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'new-booking-1-1440');

    $page->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens']));

    for ($month = 0; $month < $months; $month++) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click('button[data-date="'.$date.'"]')
        ->waitForText(adminLine('admin/customers.wizard.periods.morning'))
        ->assertScript(NEW_BOOKING_STRIP_IN_THIRDS, true)
        ->assertScript(NEW_BOOKING_DAY_BESIDE_TIMES, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'new-booking-2-1440');

    // The finished step is a live button: the keyboard is used once, focus is put on the step
    // and its outline is read against the edge that would cut it.
    $page->keys(NEW_BOOKING_FIRST_STEP, 'Shift+Tab')
        ->assertScript(NEW_BOOKING_FOCUS_FIRST_STEP, true)
        ->assertScript(Admin::focusIsOn(NEW_BOOKING_FIRST_STEP), true);
    Look::shot($page, 'new-booking-2-focus-1440');
    $page->assertScript(NEW_BOOKING_FOCUS_OUTLINE, 'clear');

    $page->click('.admin-wizard-slot[value="'.$nine.'"]')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.fields.name'))
        ->assertScript(NEW_BOOKING_SUMMARY_PLACE, 'beside')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'new-booking-3-1440');

    $page->click('.admin-wizard-cancel')
        ->assertNotPresent('.admin-wizard')
        ->assertNoJavaScriptErrors();
});

it('breaks a service name with no space in it inside the summary card on a wide screen, so it neither runs past the card nor scrolls the dialog sideways', function () {
    ['date' => $date, 'months' => $months, 'nine' => $nine] = wizardTuesday();
    $haircut = LunaFixture::id('services', 'Haircut');
    $name = 'Haircut'.str_repeat('W', 33);
    DB::table('tenant_luna.services')->where('id', $haircut)->update(['name' => $name]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->select('#wizard-service-0', (string) $haircut)
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.zone', ['zone' => 'Europe/Athens']));

    for ($month = 0; $month < $months; $month++) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click('button[data-date="'.$date.'"]')
        ->waitForText(adminLine('admin/customers.wizard.periods.morning'))
        ->click('.admin-wizard-slot[value="'.$nine.'"]')
        ->click('.admin-wizard-continue')
        ->waitForText(adminLine('admin/customers.wizard.fields.name'))
        ->assertScript(NEW_BOOKING_SUMMARY_PLACE, 'beside');
    Look::shot($page, 'new-booking-3-long-1440');

    $page->assertScript(NEW_BOOKING_SUMMARY_HOLDS_ITS_WORDS, true)
        ->assertScript(PhoneScripts::DIALOG_NO_SIDEWAYS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->click('.admin-wizard-cancel')
        ->assertNotPresent('.admin-wizard')
        ->assertNoJavaScriptErrors();
});
