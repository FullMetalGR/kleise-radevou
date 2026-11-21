<?php

use App\Http\Controllers\Admin\BlackoutController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffPhotoController;
use App\Http\Requests\StaffRequest;
use App\Infrastructure\Storage\TenantImages;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\MultipartBodies;
use Tests\Browser\Support\PhoneScripts;
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

/** The value of each Colour bookings by radio whose pill is filled dark, joined: the chosen one alone. */
const COLOUR_BY_FILLED = <<<'JS'
    Array.from(document.querySelectorAll('.admin-staff-colours__by label')).filter((pill) => pill.classList.contains('bg-foreground')).map((pill) => pill.querySelector('input').value).join(',')
    JS;

/**
 * How a member's read-only page lays its four cards: "two" where Basics runs across the page,
 * Services and Weekly availability stand side by side under it, each as wide as half of it, and
 * Exceptions runs across under both; "one" where every card stands under the one before it, each
 * as wide as Basics; "neither" for anything else.
 */
const STAFF_VIEW_COLUMNS = <<<'JS'
    document.fonts.ready.then(() => {
        const box = (name) => document.querySelector('.admin-staff-view .admin-section--' + name)?.getBoundingClientRect();
        const [basics, services, weekly, exceptions] = ['basics', 'services', 'weekly', 'exceptions'].map(box);
        if ([basics, services, weekly, exceptions].some((part) => part === undefined)) {
            return 'missing';
        }
        const near = (a, b) => Math.abs(a - b) <= 1;
        const across = (part) => near(part.left, basics.left) && near(part.right, basics.right);
        const two = services.top >= basics.bottom && near(services.top, weekly.top) && weekly.left > services.right && near(services.left, basics.left) && near(weekly.right, basics.right) && across(exceptions) && exceptions.top >= Math.max(services.bottom, weekly.bottom);
        const one = [services, weekly, exceptions].every((part, index, parts) => across(part) && part.top >= (index === 0 ? basics : parts[index - 1]).bottom);
        return two ? 'two' : one ? 'one' : 'neither';
    })
    JS;

/**
 * Whether the values a tenant types at any length keep to their place on the page in view: a
 * member's email on their read-only page and each member's name over their colours. A value is
 * kept where its words stay inside its own box, and the box inside the one around it and inside
 * the page's width. "none" where the page prints neither.
 */
const STAFF_VALUES_KEPT = <<<'JS'
    document.fonts.ready.then(() => {
        const values = Array.from(document.querySelectorAll('.admin-staff-view__email, .admin-staff-colours__member > legend'));
        const kept = (value) => {
            const box = value.getBoundingClientRect();
            return value.scrollWidth <= value.clientWidth + 1 && box.right <= value.parentElement.getBoundingClientRect().right + 1 && box.right <= document.documentElement.clientWidth;
        };
        return values.length === 0 ? 'none' : values.every(kept);
    })
    JS;

/**
 * Whether every colour swatch answers a press just outside its drawn pill: the element 1px above
 * the pill and the one 1px below it are the swatch's own label, whose radio a press picks, and the
 * layer that takes the press is 44px high. "none" where the page draws no swatch.
 */
const SWATCH_TOUCH_AREAS = <<<'JS'
    (() => {
        const chips = Array.from(document.querySelectorAll('.admin-staff-colours__chip'));
        const reached = (chip) => {
            chip.scrollIntoView({ block: 'center', behavior: 'instant' });
            const box = chip.getBoundingClientRect();
            const middle = box.left + box.width / 2;
            return [box.top - 1, box.bottom + 1].every((y) => document.elementFromPoint(middle, y) === chip) && chip.control === chip.querySelector('input') && parseFloat(getComputedStyle(chip, '::after').height) >= 44;
        };
        return chips.length === 0 ? 'none' : chips.every(reached);
    })()
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

it('moves Colour bookings by on the arrow keys in the Calendar colours card, each choice saved as it lands and its pill alone filled', function () {
    $chosen = 'input[name="staff-colour-by"]:checked';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/staff'))
        ->waitForText(adminLine('admin/staff.colours.title'))
        ->assertValue($chosen, 'staff')
        ->assertScript(COLOUR_BY_FILLED, 'staff')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'staff-list-1440');

    foreach (['category', 'status'] as $word) {
        $page->keys($chosen, 'ArrowRight')
            ->waitForText(adminLine(SettingsController::COLOUR_BY_SAVED))
            ->click('.admin-toast-dismiss')
            ->assertValue($chosen, $word)
            ->assertScript(COLOUR_BY_FILLED, $word)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
        Look::shot($page, 'staff-colours-'.$word.'-1440');

        expect(DB::table('tenant_luna.settings')->value('colour_by'))->toBe($word);
    }

    $page->assertNoJavaScriptErrors();
});

it('lays a member\'s read-only page on two columns at 1440px and on one on a phone, the list with its colours card and the page never scrolling sideways on a phone, a name and an email of one long word wrapping where they stand and each swatch answering a press just outside its pill', function (SiteLanguage $language) {
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    // A name and an email of one unbroken word each, wider than a phone.
    DB::table('tenant_luna.staff')->where('id', $elena)->update(['name' => str_repeat('Papadakipoulou', 5), 'email' => str_repeat('papadakipoulou', 4).'@luna.example']);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(390, 844);
    $page->navigate(Admin::url('luna', '/staff'))
        ->waitForText(adminLine('admin/staff.colours.title', [], $language))
        ->assertVisible('.admin-staff-search')
        ->assertScript(STAFF_VALUES_KEPT, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'staff-list-390-'.$language->value);
    $page->assertScript(SWATCH_TOUCH_AREAS, true);

    // Without the staff feature a member's page is the read-only one.
    DB::table('tenants')->where('slug', 'luna')->update(['entitlements' => '{}']);
    $page->navigate(Admin::url('luna', '/staff/'.$elena))
        ->waitForText(adminLine('admin/staff.page.exceptions', [], $language))
        ->assertPresent('.admin-staff-view')
        ->assertScript(STAFF_VIEW_COLUMNS, 'one')
        ->assertScript(STAFF_VALUES_KEPT, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'staff-view-390-'.$language->value);

    $page->resize(1440, 900)
        ->assertScript(STAFF_VIEW_COLUMNS, 'two')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'staff-view-1440-'.$language->value);
})->with([SiteLanguage::English, SiteLanguage::Greek]);
