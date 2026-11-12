<?php

use App\Http\Controllers\Admin\ServiceController;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;

/*
 * The Services screen in a real browser: sign in, create a service with a gap, see it in the
 * catalog with its sub-line, open a booked one, change the price, save, attempt the delete and
 * take the remedy, then toggle inactive rows into view. The settled states of the scaffold are
 * asserted here; the mid-load and errored states are the component tests', because a browser
 * cannot be stopped mid-load or made to fail the read honestly.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** A cancelled Haircut, so the catalog holds a service some booking item names. */
function cancelledHaircut(): void
{
    $start = athensDaysOn(61);
    $utc = fn (DateTimeImmutable $at): string => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    $id = DB::table('tenant_luna.bookings')->insertGetId([
        'source' => 'embed',
        'uuid' => (string) Str::uuid(), 'status' => 'cancelled', 'customer_name' => 'Casey Cancelled',
        'customer_email' => 'casey@example.test', 'total_minor' => 3500, 'currency' => 'EUR',
        'payment_mode' => 'onsite', 'requires_approval' => false, 'on_approval_timeout' => 'expire',
    ]);
    DB::table('tenant_luna.booking_items')->insert([
        'booking_id' => $id, 'sort' => 0,
        'service_id' => DB::table('tenant_luna.services')->where('name', 'Haircut')->value('id'),
        'staff_id' => DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id'),
        'start_utc' => $utc($start), 'end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'block_start_utc' => $utc($start), 'block_end_utc' => $utc($start->add(new DateInterval('PT45M'))),
        'price_minor' => 3500, 'blocks' => false,
    ]);
}

it('creates a service with a gap, sees it in the catalog, reprices a booked one, is refused its delete and takes the remedy, then toggles inactive rows into view', function () {
    cancelledHaircut();

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('services'))
        ->waitForText(adminLine('admin/services.table.title'))
        ->assertPathIs('/admin/services')
        ->assertSeeIn('.admin-services', 'Haircut')
        ->assertSeeIn('.admin-services', adminChoice('admin/services.table.minutes_gap', 30, ['n' => '60']))
        ->assertDontSeeIn('.admin-services', 'Perm')
        ->assertSeeIn('.admin-services-toggle', adminLine('admin/services.page.show_inactive', ['count' => '1']));

    // The list's New service button reads the form's title too, so the wait is on the form's
    // first section, which only the form shows.
    $page->click('.admin-services-new')
        ->waitForText(adminLine('admin/services.form.basics'))
        ->assertPathIs('/admin/services/new')
        ->assertSeeIn('.admin-breadcrumb__current', adminLine('admin/services.page.new'))
        ->assertCount('#service-status', 0)
        ->assertCount('.admin-section--danger', 0)
        ->fill('#service-name', 'Hot towel shave')
        ->fill('#service-duration_min', '30')
        ->fill('#service-processing_gap_min', '10')
        ->fill('#service-price', '25')
        ->click('.admin-service-save')
        ->waitForText(adminLine(ServiceController::CREATED))
        // Each success toast is dismissed the moment it is seen, so the rail is provably empty when
        // Delete is pressed below. A non-error toast clears itself after AUTO_CLEAR_MS, and the
        // empty-rail check after the refused delete must not depend on whether that timer has
        // fired: left alone, the walk reaches that point with two success toasts alive, or one, or
        // none.
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0);

    $created = DB::table('tenant_luna.services')->where('name', 'Hot towel shave')->first();
    assert($created !== null && is_int($created->id));
    expect($created->currency)->toBe('EUR')
        ->and($created->price_minor)->toBe(2500)
        ->and($created->processing_gap_min)->toBe(10);

    $page->assertPathIs('/admin/services/'.$created->id)
        ->assertSeeIn('.admin-breadcrumb__current', 'Hot towel shave')
        ->assertSeeIn('.admin-service-title .admin-chip', adminLine('admin/services.statuses.active'))
        ->click('.admin-breadcrumb__list')
        ->waitForText(adminLine('admin/services.table.title'))
        ->assertSeeIn('.admin-services', 'Hot towel shave')
        ->assertSeeIn('.admin-services', adminChoice('admin/services.table.minutes_gap', 10, ['n' => '30']));

    $page->click('Haircut')
        ->waitForText(adminLine('admin/services.danger.title'))
        ->assertValue('#service-price', '35.00')
        ->fill('#service-price', '40,00')
        ->click('.admin-service-save')
        ->waitForText(adminLine(ServiceController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertValue('#service-price', '40.00');

    expect(DB::table('tenant_luna.services')->where('name', 'Haircut')->value('price_minor'))->toBe(4000);

    $page->click('.admin-service-delete')
        ->waitForText(adminLine('admin/services.danger.deactivate'))
        ->assertSeeIn('.admin-section--danger .admin-service-conflict', adminLine(ServiceController::REFERENCED))
        ->assertCount('.admin-toast', 0)
        ->click('.admin-service-deactivate')
        ->waitForText(adminLine(ServiceController::DEACTIVATED))
        ->assertCount('.admin-service-conflict', 0)
        ->assertSeeIn('.admin-service-title .admin-chip', adminLine('admin/services.statuses.inactive'))
        ->assertSeeIn('.admin-toast', adminLine(ServiceController::DEACTIVATED));

    expect(DB::table('tenant_luna.services')->where('name', 'Haircut')->value('status'))->toBe('inactive');

    $page->click('.admin-breadcrumb__list')
        ->waitForText(adminLine('admin/services.table.title'))
        ->assertDontSeeIn('.admin-services', 'Haircut')
        ->assertSeeIn('.admin-services-toggle', adminLine('admin/services.page.show_inactive', ['count' => '2']))
        ->check('#services-show-inactive')
        ->waitForText('Perm')
        ->assertSeeIn('.admin-services', 'Haircut')
        ->assertSeeIn('.admin-services', 'Perm')
        ->assertNoJavaScriptErrors();

    // The toggle is a view filter and client state, 1.4: a reload hides the inactive rows again.
    $page->refresh()
        ->waitForText(adminLine('admin/services.table.title'))
        ->assertDontSeeIn('.admin-services', 'Perm')
        ->assertDontSeeIn('.admin-services', 'Haircut')
        ->assertSeeIn('.admin-services-toggle', adminLine('admin/services.page.show_inactive', ['count' => '2']));
});
