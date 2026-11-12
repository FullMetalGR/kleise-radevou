<?php

use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffPhotoController;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Storage\TenantImages;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\MultipartBodies;
use Tests\Support\LunaFixture;

/*
 * The Staff screen in a real browser: sign in, add a member who performs the haircut, give them
 * Monday and Wednesday windows on Shifts through the pattern editor, give them a photo, a title
 * and a bio on their page, see them offered on the booking page's staff step with the photo and
 * the title and the bio behind View profile, add a tenant-wide closure on a Monday in the booking
 * page's second week and see that day empty there, a disabled day that says so. Each success
 * toast is dismissed as seen, so no assertion rides on the auto-clear timer. The photo lands on
 * the real public disk and is removed afterwards; the multipart body reaches the form request
 * through MultipartBodies, since the plugin's own server parses none. Every word the walk presses
 * or waits on is read through the translator.
 */

/** The one face on the staff step, loaded from the tenant's own origin. */
const STAFF_FACE_LOADED = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const poll = () => {
            const faces = document.querySelectorAll('img.kr-booking__face');
            const image = faces[0];
            if (faces.length === 1 && image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0) {
                resolve(new URL(image.currentSrc || image.src).origin === location.origin);
            } else if (Date.now() - started > 5000) {
                resolve(faces.length + ' faces, not loaded');
            } else {
                setTimeout(poll, 50);
            }
        };
        poll();
    })
    JS;

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
    Storage::disk(TenantImages::DISK)->deleteDirectory('tenants/luna');
    // The plugin's server hands the kernel no files; see MultipartBodies.
    $this->app->make(Kernel::class)->prependMiddleware(MultipartBodies::class);
});

afterEach(function () {
    dropDemoSchemas();
    Storage::disk(TenantImages::DISK)->deleteDirectory('tenants/luna');
});

it('adds a member with two windows and a photo, title and bio, sees them on the booking page, then closes a Monday for everyone and sees it empty there', function () {
    $monday = firstWindowDay(1);
    $mondayButton = sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d'));

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click(navLabel('staff'))
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->assertPathIs('/admin/staff')
        ->assertSeeIn('.admin-staff', 'Luna Hair Studio')
        ->assertSeeIn('.admin-staff', 'Elena Papadaki')
        ->assertSeeIn('.admin-blackouts', '25/12/2026')
        ->click('.admin-staff-new')
        ->waitForText(adminLine('admin/staff.form.needs_name'))
        ->assertPathIs('/admin/staff/new')
        ->assertCount('.admin-section--danger', 0)
        ->assertCount('.admin-blackouts', 0)
        ->fill('#staff-name', 'Maria Ioannou')
        ->check('#staff-service-'.LunaFixture::id('services', 'Haircut'))
        ->assertSeeIn('.admin-staff-pattern', adminLine('admin/staff.pattern.none'))
        ->click('.admin-staff-save')
        ->waitForText(adminLine(StaffController::CREATED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0);

    $created = DB::table('tenant_luna.staff')->where('name', 'Maria Ioannou')->first();
    assert($created !== null && is_int($created->id));
    expect(DB::table('tenant_luna.shift_patterns')->where('staff_id', $created->id)->exists())->toBeFalse();

    // Her hours are set on Shifts: the row head's Pattern button, Monday ticked on
    // 09:00-17:00 and Wednesday on the same window, every week.
    $page->assertPathIs('/admin/staff/'.$created->id)
        ->click('.admin-staff-shifts-link')
        ->waitForText(adminLine('admin/shifts.page.lead'))
        ->click(sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__pattern', $created->id))
        ->waitForText(adminLine('admin/shifts.pattern.title', ['name' => 'Maria Ioannou']))
        ->click('.admin-shift-pattern__day[data-weekday="1"] .admin-shift-pattern__tick')
        ->click('.admin-shift-pattern__day[data-weekday="3"] .admin-shift-pattern__tick')
        ->assertSeeIn('.admin-shift-pattern__summary', adminLine('admin/shifts.pattern.hours', ['hours' => '16h']))
        ->click('.admin-shift-pattern__save')
        ->waitForText(adminLine('admin/shifts.flashes.pattern_saved'))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-shift-pattern', 0)
        ->assertScript(Admin::hoursLineIs(sprintf('.admin-shifts__row[data-staff="%d"] .admin-shifts__row-head', $created->id), 'admin/shifts.page.row_weekly', 16), true)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.shift_patterns')->where('staff_id', $created->id)->value('windows'))->toBe('[{"end": "17:00", "start": "09:00", "weekday": 1}, {"end": "17:00", "start": "09:00", "weekday": 3}]');

    $page->navigate(Admin::url('luna', '/staff/'.$created->id))
        ->waitForText(adminLine('admin/staff.page.exceptions'))
        ->assertScript(Admin::hoursLineIs('.admin-staff-pattern__line', 'admin/staff.pattern.weekly', 16), true)
        ->assertSeeIn('.admin-staff-shifts-link', adminLine('admin/staff.pattern.link'))
        ->assertSeeIn('.admin-breadcrumb__current', 'Maria Ioannou')
        ->assertCount('.admin-section--danger', 1)
        ->assertSeeIn('.admin-blackouts h2', adminLine('admin/staff.page.exceptions'))
        ->assertCount('.admin-staff-photo__image', 0)
        ->attach('#staff-photo', drawnPng(600, 600, 120, 60, 160))
        ->click('.admin-staff-photo-upload')
        ->waitForText(adminLine(StaffPhotoController::UPLOADED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertCount('.admin-staff-photo__image', 1)
        ->fill('#staff-title', 'Senior stylist')
        ->fill('#staff-bio', 'Ten years at the chair.')
        ->assertSeeIn('.admin-staff-bio__count', adminChoice('admin/staff.form.bio_count', 23, ['max' => (string) StaffRequest::BIO_MAX]))
        ->click('.admin-staff-save')
        ->waitForText(adminLine(StaffController::SAVED))
        ->click('.admin-toast-dismiss')
        ->assertCount('.admin-toast', 0)
        ->assertValue('#staff-title', 'Senior stylist')
        ->assertCount('.admin-staff-photo__image', 1)
        ->assertNoJavaScriptErrors();

    $saved = DB::table('tenant_luna.staff')->where('id', $created->id)->first(['title', 'bio', 'photo_path']);
    assert($saved !== null && is_string($saved->photo_path));
    expect($saved->title)->toBe('Senior stylist')
        ->and($saved->bio)->toBe('Ten years at the chair.')
        ->and($saved->photo_path)->toMatch('/\Atenants\/luna\/staff-[0-9a-f]{16}\.png\z/')
        ->and(Storage::disk(TenantImages::DISK)->exists($saved->photo_path))->toBeTrue();

    // The face and the title ride in the choice, the bio behind View profile beside it, and
    // opening it presses nobody.
    $choices = Flow::said('staff.any').' '.Flow::said('staff.any_line').':true|Elena Papadaki:false|Nikos Georgiou:false|Maria Ioannou Senior stylist:false';
    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->assertScript(FlowScripts::CHOICES, $choices)
        ->assertScript(STAFF_FACE_LOADED, true)
        ->assertCount('.kr-booking__bio', 0)
        ->click('.kr-booking__profile')
        ->assertSeeIn('.kr-booking__bio', 'Ten years at the chair.')
        ->assertSeeIn('.kr-booking__profile', Flow::said('staff.hide_profile'))
        ->assertScript(FlowScripts::CHOICES, $choices)
        ->assertNoJavaScriptErrors();

    // The grid opens on the tenant's current month; a Monday a week or two out may sit in the next.
    $page->click('.admin-breadcrumb__list')
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->click('.admin-blackouts__date');
    if ($monday->format('Y-m') !== athensToday()->format('Y-m')) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }
    $page->click(sprintf('button[data-date="%s"]', $monday->format('Y-m-d')))
        ->fill('#blackout-reason', 'Stocktake')
        ->click('.admin-blackouts__add')
        ->waitForText(adminLine(BlackoutController::ADDED))
        ->click('.admin-toast-dismiss')
        ->assertSeeIn('.admin-blackouts', $monday->format('d/m/Y'))
        ->assertSeeIn('.admin-blackouts', 'Stocktake')
        ->assertNoJavaScriptErrors();

    $booking = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    // An empty day cannot be pressed: it is a disabled day naming its state, and the week
    // opens on another day.
    Flow::weekOf($booking, $monday)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(sprintf("document.querySelector('%s').disabled", $mondayButton), true)
        ->assertScript(sprintf("document.querySelector('%s').getAttribute('aria-label').endsWith(%s)", $mondayButton, json_encode(Flow::said('when.closed_day', ['date' => '']), JSON_THROW_ON_ERROR)), true)
        ->assertScript(sprintf("document.querySelector('%s').getAttribute('aria-pressed')", $mondayButton), 'false')
        ->assertScript(sprintf("document.querySelector('%s .kr-booking__no-times') !== null", $mondayButton), true);
});

it('lists Hours this week beside each member, opens Shifts from the list and from a member\'s Weekly availability, and draws each day there', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/staff'))
        ->waitForText(adminLine('admin/staff.table.hours'))
        ->assertSeeIn('.admin-staff h2', adminLine('admin/staff.table.title'))
        ->assertCount('.admin-staff__hours', 3)
        ->click('.admin-staff-shifts')
        ->waitForText(adminLine('admin/shifts.page.lead'))
        ->assertPathIs('/admin/shifts')
        ->assertSeeIn('.admin-shifts-screen h1', adminLine('admin/shifts.page.title'))
        ->assertCount('.admin-shifts__row', 2)
        ->assertSeeIn('.admin-shifts__row[data-staff="'.LunaFixture::id('staff', 'Elena Papadaki').'"] .admin-shifts__name', 'Elena Papadaki')
        ->assertAttribute('.admin-rail [aria-current="page"]', 'href', '/admin/shifts')
        ->assertNoJavaScriptErrors();

    $page->navigate(Admin::url('luna', '/staff/'.LunaFixture::id('staff', 'Elena Papadaki')))
        ->waitForText(adminLine('admin/staff.form.weekly'))
        ->click('.admin-staff-shifts-link')
        ->waitForText(adminLine('admin/shifts.page.lead'))
        ->assertPathIs('/admin/shifts')
        ->assertNoJavaScriptErrors();
});

it('adds a closure from a member\'s own page, the card\'s Add sending the closure and never the member\'s form, and lists it there', function () {
    $day = firstWindowDay(2);
    $elena = LunaFixture::id('staff', 'Elena Papadaki');

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->navigate(Admin::url('luna', '/staff/'.$elena))
        ->waitForText(adminLine('admin/staff.page.exceptions'))
        ->click('.admin-blackouts__date');
    if ($day->format('Y-m') !== athensToday()->format('Y-m')) {
        $page->click(sprintf('.admin-day-calendar button[aria-label="%s"]', adminLine('admin/shell.kit.month_grid.next')));
    }
    $page->click(sprintf('button[data-date="%s"]', $day->format('Y-m-d')))
        ->fill('#exception-reason', 'Training')
        ->click('.admin-blackouts__add')
        ->waitForText(adminLine(BlackoutController::ADDED))
        ->click('.admin-toast-dismiss')
        ->assertSeeIn('.admin-blackouts', $day->format('d/m/Y'))
        ->assertSeeIn('.admin-blackouts', 'Training')
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.blackouts')->where('staff_id', $elena)->where('date', $day->format('Y-m-d'))->value('reason'))->toBe('Training');
});
