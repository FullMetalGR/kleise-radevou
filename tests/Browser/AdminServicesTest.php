<?php

use App\Http\Controllers\Admin\ServiceController;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Look;
use Tests\Browser\Support\PhoneScripts;

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

/**
 * True when the service form's cards stand on two columns: Timing beside the next card and Booking
 * rules beside Payment, each pair sharing a top and together as wide as Basics, which runs across
 * both columns above them, as does the danger zone below them where the page has one.
 */
const SERVICE_FORM_ON_TWO_COLUMNS = <<<'JS'
    document.fonts.ready.then(() => {
        const box = (name) => document.querySelector('.admin-section--' + name)?.getBoundingClientRect();
        const [basics, timing, staff, rules, payment, danger] = ['basics', 'timing', 'staff', 'rules', 'payment', 'danger'].map(box);
        if ([basics, timing, staff, rules, payment].some((part) => part === undefined)) {
            return false;
        }
        const near = (a, b) => Math.abs(a - b) <= 1;
        const across = (part) => near(part.left, basics.left) && near(part.right, basics.right);
        const beside = (left, right) => near(left.top, right.top) && right.left > left.right && near(left.left, basics.left) && near(right.right, basics.right);
        return timing.top >= basics.bottom && beside(timing, staff) && beside(rules, payment) && (danger === undefined || (across(danger) && danger.top >= payment.bottom));
    })
    JS;

/** True when every card of the service form stands under the one before it, each as wide as the first. */
const SERVICE_FORM_ON_ONE_COLUMN = <<<'JS'
    document.fonts.ready.then(() => {
        const parts = Array.from(document.querySelectorAll('.admin-service-form .admin-section')).map((part) => part.getBoundingClientRect());
        return parts.length > 1 && parts.every((part, index) => Math.abs(part.left - parts[0].left) <= 1 && Math.abs(part.width - parts[0].width) <= 1 && (index === 0 || part.top >= parts[index - 1].bottom));
    })
    JS;

/**
 * True when each member's check card says whether it is chosen by its box, its 2px ring in the
 * theme's main colour and its tick together, and an unchosen card shows neither that ring nor the
 * tick: its ring is the thin one, in a colour that is not the theme's. A ring is read as the
 * browser draws it, one shadow of the card's among several, and the theme's is drawn on a probe
 * beside the card, so the two are spelled alike whatever colour the theme is.
 */
const SERVICE_CHECK_CARDS_SHOW_CHOICE = <<<'JS'
    document.fonts.ready.then(() => {
        const rows = Array.from(document.querySelectorAll('.admin-staff-checklist__row'));
        if (rows.length < 2) {
            return false;
        }
        const [thickInTheme, thinInTheme] = ['2px', '1.5px'].map((width) => {
            const probe = document.createElement('span');
            probe.style.boxShadow = '0 0 0 ' + width + ' var(--primary)';
            rows[0].parentElement.append(probe);
            const drawn = getComputedStyle(probe).boxShadow;
            probe.remove();
            return drawn;
        });
        const shadows = (row) => getComputedStyle(row).boxShadow.split(/,(?![^(]*\))/).map((shadow) => shadow.trim());
        return rows.every((row) => {
            const chosen = row.querySelector('input[type="checkbox"]').checked;
            const drawn = shadows(row);
            return row.tagName === 'LABEL'
                && drawn.includes(thickInTheme) === chosen
                && drawn.some((shadow) => shadow.endsWith(' 0px 0px 0px 1.5px')) === !chosen
                && !drawn.includes(thinInTheme)
                && (row.querySelector('.admin-staff-checklist__mark svg') !== null) === chosen;
        });
    })
    JS;

/**
 * How far into the catalog's card its heading and the first text of its first row start, in whole
 * pixels from the card's left edge: the two stand on one line down the card when the numbers
 * match. A row's first text is the first it draws, a cell's name where the rows show one and the
 * cell's value in the grid.
 */
const SERVICES_HEADING_AND_ROW_INSETS = <<<'JS'
    document.fonts.ready.then(() => {
        const edge = document.querySelector('.admin-services').getBoundingClientRect().left;
        const firstDrawn = (root) => {
            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
            for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
                const range = document.createRange();
                range.selectNodeContents(node);
                const box = range.getBoundingClientRect();
                if (node.textContent.trim() !== '' && box.width > 0) {
                    return Math.round(box.left - edge);
                }
            }
            return null;
        };
        return { heading: firstDrawn(document.querySelector('#services-head')), row: firstDrawn(document.querySelector('.admin-services__row')) };
    })
    JS;

/** True when the catalog reads as rows, each service a block of named lines, and false when it is a grid. */
const SERVICES_CATALOG_AS_ROWS = "getComputedStyle(document.querySelector('.admin-services__row')).display === 'block'";

/**
 * How the members' check cards fill their section: how many stand side by side in the first row,
 * and by how many whole pixels the card reaching furthest right runs past the room the section
 * gives its list, none where every card ends inside it. The section clips what it holds, so a
 * card past that edge is cut off there and never widens the page.
 */
const SERVICE_CHECK_CARDS_ACROSS_AND_PAST = <<<'JS'
    document.fonts.ready.then(() => {
        const list = document.querySelector('.admin-staff-checklist');
        const cards = Array.from(list.querySelectorAll('.admin-staff-checklist__row')).map((card) => card.getBoundingClientRect());
        const body = list.parentElement;
        const room = body.getBoundingClientRect().right - parseFloat(getComputedStyle(body).paddingRight);
        const across = cards.filter((card, index) => Math.abs(card.top - cards[0].top) <= 1 && (index === 0 || card.left >= cards[index - 1].right)).length;
        return [across, Math.max(0, Math.round(Math.max(...cards.map((card) => card.right)) - room))];
    })
    JS;

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

it('lays the service form on two columns at 1440px, Basics and the danger zone across both, and ticks a member\'s check card by a press anywhere on it or by the space key', function () {
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    $card = '.admin-staff-checklist__row:has(#service-staff-'.$elena.')';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);
    $page->navigate(Admin::url('luna', '/services'))
        ->waitForText(adminLine('admin/services.table.title'))
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'services-list-1440');

    $page->click('.admin-services-new')
        ->waitForText(adminLine('admin/services.form.basics'))
        ->assertCount('.admin-section--danger', 0)
        ->assertScript(SERVICE_FORM_ON_TWO_COLUMNS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'services-new-1440');

    // Beard trim is Nikos's alone, so Elena's card starts unchosen.
    $page->navigate(Admin::url('luna', '/services'))
        ->waitForText('Beard trim')
        ->click('Beard trim')
        ->waitForText(adminLine('admin/services.danger.title'))
        ->assertScript(SERVICE_FORM_ON_TWO_COLUMNS, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertNotChecked('#service-staff-'.$elena)
        ->assertScript(SERVICE_CHECK_CARDS_SHOW_CHOICE, true)
        ->click($card.' .admin-staff-checklist__windows')
        ->assertChecked('#service-staff-'.$elena)
        ->assertScript(SERVICE_CHECK_CARDS_SHOW_CHOICE, true);
    Look::shot($page, 'services-detail-1440');

    $page->keys('#service-staff-'.$elena, 'Space')
        ->assertNotChecked('#service-staff-'.$elena)
        ->assertScript(SERVICE_CHECK_CARDS_SHOW_CHOICE, true)
        ->assertScript(PhoneScripts::SAVE_BAR_CLEARS_LAST_CONTROL, true)
        ->assertNoJavaScriptErrors();
});

it('keeps the service form on one column on a phone in Greek, the save bar resting after its last control, on the list, a new service and an inactive one', function () {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => SiteLanguage::Greek->value]);
    $perm = DB::table('tenant_luna.services')->where('name', 'Perm')->value('id');
    assert(is_int($perm));

    $page = Admin::signInOnPhone($this, 'luna', Admin::owner('luna'))->assertVisible(Admin::HOME);
    $page->navigate(Admin::url('luna', '/services'))
        ->waitForText(adminLine('admin/services.table.title', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-services-toggle', adminLine('admin/services.page.show_inactive', ['count' => '1'], SiteLanguage::Greek))
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
    Look::shot($page, 'services-list-390-el');

    $page->click('.admin-services-new')
        ->waitForText(adminLine('admin/services.form.basics', [], SiteLanguage::Greek))
        ->assertScript(SERVICE_FORM_ON_ONE_COLUMN, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(PhoneScripts::SAVE_BAR_CLEARS_LAST_CONTROL, true);
    Look::shot($page, 'services-new-390-el');

    $page->navigate(Admin::url('luna', '/services/'.$perm))
        ->waitForText(adminLine('admin/services.danger.title', [], SiteLanguage::Greek))
        ->assertSeeIn('.admin-service-title .admin-chip', adminLine('admin/services.statuses.inactive', [], SiteLanguage::Greek))
        ->assertScript(SERVICE_FORM_ON_ONE_COLUMN, true)
        ->assertScript(SERVICE_CHECK_CARDS_SHOW_CHOICE, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true)
        ->assertScript(PhoneScripts::SAVE_BAR_CLEARS_LAST_CONTROL, true)
        ->assertNoJavaScriptErrors();
    Look::shot($page, 'services-inactive-390-el');
});

it('starts the catalog card\'s heading where its rows start at every width, the 940px window included, where the open sidebar leaves the card too narrow for a grid', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));

    // At 940px the window is wide and the card is not: the catalog turns to rows by its own width.
    foreach ([390 => true, 800 => true, 940 => true, 1440 => false] as $width => $asRows) {
        $page->resize($width, 900)
            ->navigate(Admin::url('luna', '/services'))
            ->waitForText(adminLine('admin/services.table.title'))
            ->assertScript(SERVICES_CATALOG_AS_ROWS, $asRows)
            ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

        if ($width >= 900) {
            $page->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true');
        }

        $insets = $page->script(SERVICES_HEADING_AND_ROW_INSETS);
        assert(is_array($insets));
        Look::shot($page, 'services-catalog-'.$width);

        expect($insets['heading'] ?? null)->toBeInt()->toBe($insets['row'] ?? null, 'At '.$width.'px the heading and the first row start apart.');
    }
});

it('stands two of the staff check cards side by side at 1100px, where the form is one column wide enough for two, each inside its section and its box read by the member\'s name alone', function () {
    $elena = DB::table('tenant_luna.staff')->where('name', 'Elena Papadaki')->value('id');
    assert(is_int($elena));
    $box = 'internal:role=checkbox[name="Elena Papadaki"]';

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1100, 900);
    $page->navigate(Admin::url('luna', '/services'))
        ->waitForText('Beard trim')
        ->click('Beard trim')
        ->waitForText(adminLine('admin/services.danger.title'))
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertScript(SERVICE_FORM_ON_ONE_COLUMN, true)
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    expect($page->script(SERVICE_CHECK_CARDS_ACROSS_AND_PAST))->toBe([2, 0]);
    Look::shot($page, 'services-detail-1100');

    // The card is a label around the box, the weekly hours inside it. The box is still found by
    // its role under the member's name with nothing after it, so the hours are not read as its name.
    $page->assertCount($box, 1)
        ->assertAttribute($box, 'id', 'service-staff-'.$elena)
        ->assertNoJavaScriptErrors();
});
