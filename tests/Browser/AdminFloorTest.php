<?php

use App\Http\Controllers\Admin\BlackoutController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Pest\Browser\Api\Webpage;
use Tests\Browser\Support\Admin;

/*
 * The floor as lived, in a real browser and through the resolver that reads the tenant row, no
 * test binding anywhere: luna, provisioned with the staff feature, loses it while holding three
 * active members, and the shell names what went on every page while Settings shows the read-only
 * roster; pier, which never had it and holds its implicit member alone, keeps the opening-hours
 * editor and sees no banner.
 *
 * Without the feature the Staff screen stays readable and offers no control the server refuses;
 * luna's members read as stored with no New staff member, Save, Delete or closures form, while
 * the tenant-wide closures card, which the floor keeps, still adds and removes. pier's screen is
 * that card alone. The screen keeps its rail entry without the feature, labelled Closures where
 * Staff stands with it: pier's screen is headed Closures, luna's read-only list keeps its Staff
 * heading.
 *
 * A narrowing record is a subscription's resolve's, so luna is made a paying tenant before it is
 * narrowed: the demo seed builds it in the free shape, whose plan is the console's, and there the
 * sentence names platform support and links nothing, the last case.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(function () {
    dropDemoSchemas();
});

/**
 * Gives the demo tenant a subscriptions row, so it is a paying tenant whose plan is billing's
 * rather than the free shape the demo seed builds.
 */
function floorPaying(string $slug): void
{
    DB::table('subscriptions')->insert(['tenant_id' => DB::table('tenants')->where('slug', $slug)->value('id'), 'provider_customer_id' => 'cus_'.$slug]);
}

/**
 * Adds a tenant-wide closure on the day given from the card's own form, the grid opening on the
 * tenant's current month, reads it on the card, removes it, and reads the card empty again.
 *
 * @param  Webpage  $page  the Staff screen with the closures card on it and no closure on record
 */
function floorClosureAddedAndRemoved($page, DateTimeImmutable $day): void
{
    $page->click('.admin-blackouts__date');

    if ($day->format('Y-m') !== athensToday()->format('Y-m')) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }

    $page->click(sprintf('button[data-date="%s"]', $day->format('Y-m-d')))
        ->click('.admin-blackouts__add')
        ->waitForText(adminLine(BlackoutController::ADDED))
        ->click('.admin-toast-dismiss')
        ->assertSeeIn('.admin-blackouts', $day->format('d/m/Y'))
        ->click('.admin-blackouts__delete')
        ->waitForText(adminLine(BlackoutController::REMOVED))
        ->click('.admin-toast-dismiss')
        ->assertSeeIn('.admin-blackouts', adminLine('admin/staff.closures.none'));
}

it('names the lost feature on every page and shows the read-only roster on a tenant that lost it with three active members', function () {
    floorPaying('luna');
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}', 'narrowed' => '{staff}']);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->assertSeeIn('.admin-narrowed', adminLine('admin/shell.banners.features.staff.gone', ['billing' => adminLine('admin/shell.banners.billing')]))
        ->assertPresent('.admin-narrowed a.admin-narrowed__billing')
        ->assertSeeIn(Admin::STAFF_ENTRY, navLabel('closures'))
        ->assertDontSeeIn('.admin-rail', navLabel('staff'))
        ->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.sections.opening_hours'))
        ->assertPathIs('/admin/settings')
        ->assertPresent('.admin-narrowed')
        ->assertSeeIn('.admin-settings-roster', adminLine('admin/settings.hours.roster'))
        ->assertScript("document.querySelectorAll('.admin-settings-roster__member').length", 3)
        ->assertMissing('.admin-settings-hours-save');
});

it('keeps the opening-hours editor and shows no banner on a tenant that never had the feature', function () {
    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));
    $page->assertMissing('.admin-narrowed')
        ->click(navLabel('settings'))
        ->waitForText(adminLine('admin/settings.sections.opening_hours'))
        ->assertPathIs('/admin/settings')
        ->assertPresent('.admin-settings-hours-save')
        ->assertMissing('.admin-settings-roster');
});

it('opens Closures from the rail onto the Staff screen of a tenant that lost the feature, read-only under its Staff heading, its tenant-wide closures card still adding and removing', function () {
    floorPaying('luna');
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}', 'narrowed' => '{staff}']);
    DB::table('tenant_luna.blackouts')->whereNull('staff_id')->delete();
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    $closure = athensDaysOn(64);
    DB::table('tenant_luna.blackouts')->insert(['staff_id' => $elena, 'date' => $closure->format('Y-m-d'), 'start_time' => '14:00', 'end_time' => '16:00', 'reason' => 'Dentist']);
    $day = athensToday()->add(new DateInterval('P10D'));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->assertSeeIn(Admin::STAFF_ENTRY, navLabel('closures'))
        ->click(Admin::STAFF_ENTRY)
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->assertPathIs('/admin/staff')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/staff')
        ->assertTitle(adminLine('admin/staff.page.title').' - '.siteName())
        ->assertSeeIn('.admin-staff-screen h1', adminLine('admin/staff.page.title'))
        ->assertSeeIn('.admin-narrowed', adminLine('admin/shell.banners.features.staff.gone', ['billing' => adminLine('admin/shell.banners.billing')]))
        ->assertPresent('.admin-narrowed a.admin-narrowed__billing')
        ->assertMissing('.admin-staff-readonly')
        ->assertMissing('.admin-staff-new')
        ->assertSeeIn('.admin-staff', 'Luna Hair Studio')
        ->assertSeeIn('.admin-staff', 'Nikos Georgiou')
        ->assertSeeIn('.admin-blackouts', adminLine('admin/staff.closures.none'))
        ->click('Elena Papadaki')
        ->waitForText(adminLine('admin/staff.page.exceptions'))
        ->assertPathIs('/admin/staff/'.$elena)
        ->assertSeeIn('.admin-staff-view__closures', $closure->format('d/m/Y'))
        ->assertSeeIn('.admin-staff-view__closures', 'Dentist')
        ->assertScript(Admin::hoursLineIs('.admin-staff-pattern__line', 'admin/staff.pattern.weekly', 40), true)
        ->assertSeeIn('.admin-staff-shifts-link', adminLine('admin/staff.pattern.link'))
        ->assertMissing('.admin-staff-save')
        ->assertMissing('.admin-staff-delete')
        ->assertMissing('.admin-blackouts')
        ->assertMissing('.admin-weekly')
        ->click('.admin-breadcrumb__list')
        ->waitForText(adminLine('admin/staff.table.hours'));
    floorClosureAddedAndRemoved($page, $day);
    $page->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.blackouts')->whereNull('staff_id')->count())->toBe(0);
});

it('opens Closures from the rail onto the screen headed Closures on a tenant that never had the feature, its tenant-wide closures card adding and removing and no member', function () {
    $day = athensToday()->add(new DateInterval('P10D'));

    $page = Admin::signedIn($this, 'pier', Admin::owner('pier'));
    $page->assertSeeIn(Admin::STAFF_ENTRY, navLabel('closures'))
        ->click(Admin::STAFF_ENTRY)
        ->waitForText(adminLine('admin/staff.closures.none'))
        ->assertPathIs('/admin/staff')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/staff')
        ->assertPresent('.admin-staff-screen--floor')
        ->assertSeeIn('.admin-staff-screen--floor h1', adminLine('admin/staff.page.floor_title'))
        ->assertTitle(adminLine('admin/staff.page.floor_title').' - '.siteName())
        ->assertMissing('.admin-staff')
        ->assertMissing('.admin-staff-new');
    floorClosureAddedAndRemoved($page, $day);
    $page->assertMissing('.admin-staff')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_pier.blackouts')->whereNull('staff_id')->count())->toBe(0);
});

it('names platform support and links nothing on a free tenant\'s read-only Staff screen, its plan the console\'s, the narrowed paying tenant\'s Billing link left to the cases above', function () {
    // luna as the demo seed builds it is the free shape; the console took Staff away, which clears
    // the narrowing record, so the screen's own notice speaks.
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}', 'narrowed' => '{}']);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(Admin::STAFF_ENTRY)
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->assertPathIs('/admin/staff')
        ->assertMissing('.admin-narrowed')
        ->assertSeeIn('.admin-staff-readonly', adminLine('admin/shell.banners.features.staff.gone_free'))
        ->assertMissing('.admin-staff-readonly a')
        ->assertMissing('.admin-staff-readonly__billing')
        ->assertNoJavaScriptErrors();
});
