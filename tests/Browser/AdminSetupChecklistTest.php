<?php

use App\Http\Controllers\Admin\NewBookingController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;

/*
 * The setup checklist in a real browser: a tenant fresh from tenant:provision has no service, no
 * booking and neither marker, so its owner meets the card with four open circles, follows each
 * item's link, adds a service, saves the opening hours, copies the snippet and takes a booking
 * through the wizard the card opens, and on the next load the card is gone. The provisioner seeds
 * five default rules and the implicit staff row, so "hours set" is the marker alone. No browser
 * grants the clipboard here, so the page's writeText is stood in for before the press, as
 * AdminShellTest redefines localStorage.
 *
 * A service created from the admin of a tenant on the floor with the implicit row alone is linked
 * to that row on create, in the insert's transaction, so the walk reads the link the create wrote
 * rather than writing one, and the wizard finds the service performed.
 */

const SETUP_SLUG = 'fern';

const SETUP_PASSWORD = 'correct horse battery staple';

beforeEach(function () {
    DB::statement(sprintf('drop schema if exists tenant_%s cascade', SETUP_SLUG));

    expect(Artisan::call('tenant:provision', [
        'slug' => SETUP_SLUG,
        '--name' => 'Fern Studio',
        '--timezone' => 'Europe/Athens',
        '--currency' => 'EUR',
        '--owner-name' => 'Fay Owner',
        '--owner-email' => Admin::owner(SETUP_SLUG),
        '--owner-password' => SETUP_PASSWORD,
    ]))->toBe(0);
});

afterEach(fn () => DB::statement(sprintf('drop schema if exists tenant_%s cascade', SETUP_SLUG)));

/**
 * The Tuesday of the second availability window in Athens, firstWindowDay's, a weekday the
 * default rules open from 09:00, how many months ahead of today it lies, and its 09:00 as the
 * wire spells it.
 *
 * @return array{date: string, months: int, nine: string}
 */
function setupTuesday(): array
{
    $today = athensToday();
    $day = firstWindowDay(2);
    $months = ((int) $day->format('Y') - (int) $today->format('Y')) * 12 + (int) $day->format('n') - (int) $today->format('n');

    $nine = $day->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'))->format(DateTimeImmutable::RFC3339);

    return ['date' => $day->format('Y-m-d'), 'months' => $months, 'nine' => $nine];
}

function setupItem(string $fact): string
{
    return sprintf('.admin-setup__item[data-fact="%s"]', $fact);
}

it('walks a fresh tenant through the four items from the card, and the card is gone on the next load', function () {
    ['date' => $date, 'months' => $months, 'nine' => $nine] = setupTuesday();
    $home = Admin::url(SETUP_SLUG);

    $page = Admin::signedIn($this, SETUP_SLUG, Admin::owner(SETUP_SLUG), SETUP_PASSWORD);
    $page->assertPathIs('/admin')
        ->assertSeeIn('.admin-setup', adminLine('admin/home.setup.title'))
        ->assertCount('.admin-setup__mark--open', 4)
        ->assertAttribute('.admin-setup__hosted', 'href', hostedPage(SETUP_SLUG));

    $page->click(setupItem('service').' a')
        ->waitForText(adminLine('admin/services.form.basics'))
        ->assertPathIs('/admin/services/new')
        ->fill('#service-name', 'Trim')
        ->fill('#service-duration_min', '30')
        ->fill('#service-price', '20')
        ->click('.admin-service-save')
        ->waitForText(adminLine(ServiceController::CREATED));

    // The create linked Trim to the implicit member, the tenant's only staff row (P9F-53), so the
    // wizard below finds it performed with no link written by hand.
    expect(DB::table('tenant_'.SETUP_SLUG.'.service_staff')->get(['service_id', 'staff_id'])->map(fn (stdClass $link): array => [$link->service_id, $link->staff_id])->all())->toBe([[
        DB::table('tenant_'.SETUP_SLUG.'.services')->where('name', 'Trim')->value('id'),
        DB::table('tenant_'.SETUP_SLUG.'.staff')->where('is_implicit', true)->value('id'),
    ]]);

    $page->navigate($home)
        ->waitForText(adminLine('admin/home.setup.title'))
        ->assertAttribute(setupItem('service'), 'data-done', 'true')
        ->assertAttribute(setupItem('hours'), 'data-done', 'false')
        ->click(setupItem('hours').' a')
        ->waitForText(adminLine('admin/settings.sections.opening_hours'))
        ->assertPathIs('/admin/settings')
        ->click('.admin-settings-hours-save')
        ->waitForText(adminLine(SettingsController::HOURS_SAVED));

    $page->navigate($home)
        ->waitForText(adminLine('admin/home.setup.title'))
        ->assertAttribute(setupItem('hours'), 'data-done', 'true')
        ->assertAttribute(setupItem('link'), 'data-done', 'false')
        ->click(setupItem('link').' a')
        ->waitForText(adminLine('admin/settings.booking_page.copy'))
        ->assertPathIs('/admin/settings');

    $page->script('navigator.clipboard.writeText = () => Promise.resolve()');
    $page->click('.admin-settings-copy')
        ->waitForText(adminLine('admin/shell.copy.copied'))
        ->waitForEvent('networkidle');

    $page->navigate($home)
        ->waitForText(adminLine('admin/home.setup.title'))
        ->assertAttribute(setupItem('link'), 'data-done', 'true')
        ->assertAttribute(setupItem('booking'), 'data-done', 'false')
        ->assertCount('.admin-setup__mark--open', 1)
        ->click('.admin-setup__book')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->assertSeeIn('.admin-wizard-strip', 'Trim')
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
        ->type('#wizard-customer-name', 'Wanda Walkin')
        ->type('#wizard-customer-email', 'wanda@example.test')
        ->click('.admin-wizard-create')
        ->waitForText(adminLine(NewBookingController::CREATED));

    $page->navigate($home)
        ->assertVisible(Admin::HOME)
        ->assertPresent('.admin-home-figures')
        ->assertMissing('.admin-setup');

    expect(DB::table('tenant_'.SETUP_SLUG.'.settings')->where('id', 1)->whereNotNull('hours_confirmed_at')->whereNotNull('link_copied_at')->exists())->toBeTrue()
        ->and(DB::table('tenant_'.SETUP_SLUG.'.bookings')->where('customer_name', 'Wanda Walkin')->exists())->toBeTrue();
});
