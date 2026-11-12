<?php

use App\Http\Controllers\Admin\NewBookingController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
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
        ->assertSeeIn('.admin-booking-facts .admin-chip', adminLine('admin/shell.kit.statuses.confirmed'))
        ->assertCount('.admin-booking-items__row', 2)
        ->assertCount('.admin-booking-trail__entry', 1)
        ->assertSeeIn('.admin-booking-trail__entry', 'Demo Owner');

    expect(DB::table('tenant_luna.bookings')->where('customer_name', 'Wanda Walkin')->value('status'))->toBe('confirmed');
});
