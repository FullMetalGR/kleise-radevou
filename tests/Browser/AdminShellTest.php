<?php

use App\Http\Controllers\Admin\LoginController;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Site\SiteLanguage;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\PhoneScripts;

/*
 * The door and the frame of the admin in a real browser: a tenant's user signs in at their own
 * subdomain over a session that lives across requests, sees the shell with the rail their
 * permissions allow, and signs out again to a toast.
 */

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

/** The sidebar's column width once its eased change of width has settled. */
const SHELL_SIDEBAR_WIDTH = "new Promise((done) => setTimeout(() => done(Math.round(document.querySelector('.admin-sidebar').getBoundingClientRect().width)), 700))";

/** What the tooltip beside a folded entry reads, once the pause before it shows has passed. */
const SHELL_TOOLTIP = "new Promise((done) => setTimeout(() => done(document.querySelector('.admin-tooltip')?.firstChild?.textContent ?? null), 700))";

/** True where no tooltip shows once the pause before one has passed. */
const SHELL_NO_TOOLTIP = "new Promise((done) => setTimeout(() => done(document.querySelector('.admin-tooltip') === null), 700))";

/** True when the keyboard's focus is on the button that folds the sidebar. */
const SHELL_FOCUS_ON_TOGGLE = "document.activeElement !== null && document.activeElement === document.querySelector('.admin-sidebar-toggle')";

/** True when the element is cut with an ellipsis: its text runs wider than its box. */
const SHELL_CUT = "document.fonts.ready.then(() => { const box = document.querySelector('%s'); return box !== null && box.scrollWidth > box.clientWidth && getComputedStyle(box).textOverflow === 'ellipsis'; })";

/** True when New booking stands whole inside the window and the page scrolls nothing sideways. */
const SHELL_NEW_BOOKING_INSIDE = "document.fonts.ready.then(() => { const box = document.querySelector('.admin-new-booking')?.getBoundingClientRect(); const width = document.documentElement.clientWidth; return box !== undefined && box.left >= 0 && box.right <= width + 1 && box.top >= 0 && document.documentElement.scrollWidth <= width + 1; })";

/**
 * True when the first row's Menu button and New booking, an icon button below the wide breakpoint,
 * each stand whole inside the window and are round, as wide as they are tall, and the page
 * scrolls nothing sideways.
 */
const SHELL_ROUND_BUTTONS_INSIDE = "document.fonts.ready.then(() => { const width = document.documentElement.clientWidth; const boxes = ['.admin-topbar .admin-menu', '.admin-topbar .admin-new-booking'].map((selector) => document.querySelector(selector)?.getBoundingClientRect()); return boxes.every((box) => box !== undefined && box.left >= 0 && box.right <= width + 1 && box.top >= 0 && Math.round(box.width) === Math.round(box.height)) && document.documentElement.scrollWidth <= width + 1; })";

/** The Services table's rows: as blocks with their column names, or as the grid's rows. */
const SHELL_SERVICES_ROWS = "document.fonts.ready.then(() => { const box = document.querySelector('.admin-services-screen .admin-table-scroll'); const rows = [...document.querySelectorAll('.admin-services__row')]; return box !== null && rows.length > 0 && box.scrollWidth <= box.clientWidth + 1 ? [...new Set(rows.map((row) => getComputedStyle(row).display))].join() : 'none'; })";

it('signs luna\'s owner in, lands on Home inside the shell, and signs out to a toast', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertVisible(Admin::HOME)
        ->assertSeeIn('.admin-business', 'Luna Hair Studio')
        ->assertCount('.admin-rail-entry', 11)
        ->assertSeeIn('.admin-rail a[href="/admin"]', navLabel('home'))
        ->assertCount('[aria-current="page"]', 1)
        ->assertAttribute('[aria-current="page"]', 'href', '/admin')
        ->assertCount('.admin-rail-group', 2)
        ->assertNoJavaScriptErrors();

    $page->click('.admin-rail a[href="/admin/bookings"]')
        ->assertPathIs('/admin/bookings')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/bookings');

    $page->click('.admin-signout')
        ->waitForText(adminLine(LoginController::SIGNED_OUT))
        ->assertPathIs('/admin/login')
        ->assertSeeIn('.admin-toast', adminLine(LoginController::SIGNED_OUT));

    $this->visit(Admin::url('luna'))->assertPathIs('/admin/login');
});

it('answers a wrong password with the one sentence on the email field, politely, focus moved to it, and stays on the form', function () {
    Admin::signIn($this, 'luna', Admin::owner('luna'), 'not the password')
        ->waitForText(adminLine(LoginController::REFUSED))
        ->assertPathIs('/admin/login')
        ->assertSeeIn('#login-email-error', adminLine(LoginController::REFUSED))
        ->assertAttribute('#login-email', 'aria-describedby', 'login-email-error')
        ->assertAttribute('#login-email', 'aria-invalid', 'true')
        ->assertScript('document.activeElement === null ? null : document.activeElement.id', 'login-email')
        ->assertScript(FlowScripts::NO_ALERT, true)
        ->assertButtonEnabled(adminLine('admin/shell.login.submit'));

    $this->visit(Admin::url('luna'))->assertPathIs('/admin/login');
});

it('refuses pier\'s owner at luna with pier\'s own password', function () {
    Admin::signIn($this, 'luna', Admin::owner('pier'))
        ->waitForText(adminLine(LoginController::REFUSED))
        ->assertPathIs('/admin/login');
});

it('sends an unauthenticated visitor of any admin page to the sign-in page and back after it', function () {
    $this->visit(Admin::url('luna', '/bookings'))
        ->assertPathIs('/admin/login')
        ->fill('email', Admin::owner('luna'))
        ->fill('password', Admin::DEMO_PASSWORD)
        ->click('.admin-login-submit')
        ->waitForText('Luna Hair Studio')
        ->assertPathIs('/admin/bookings');
});

it('answers the retired My calendar address with the Calendar', function () {
    Admin::signedIn($this, 'luna', Admin::owner('luna'));

    $this->visit(Admin::url('luna', '/my-calendar'))
        ->assertVisible(Admin::RAIL)
        ->assertPathIs('/admin/calendar')
        ->assertAttribute('[aria-current="page"]', 'href', '/admin/calendar');
});

it('does not carry a session at luna to pier', function () {
    Admin::signedIn($this, 'luna', Admin::owner('luna'))->assertPathIs('/admin');

    $this->visit(Admin::url('pier'))->assertPathIs('/admin/login');
});

it('gives a staff user a rail of the Calendar alone, with no heading, and lands them there pinned to Me', function () {
    expect(Artisan::call('tenant:add-user', ['slug' => 'luna', '--name' => 'Sam Staff', '--email' => 'sam@luna.example', '--password' => 'staff password', '--role' => 'staff']))->toBe(0);

    Admin::signIn($this, 'luna', 'sam@luna.example', 'staff password')
        ->waitForText('Sam Staff')
        ->assertPathIs('/admin/calendar')
        ->assertCount('.admin-rail-entry', 1)
        ->assertSeeIn('.admin-rail', navLabel('calendar'))
        ->assertCount('.admin-rail-group', 0);
});

it('renders an unknown admin address as the 404 page inside the shell', function () {
    Admin::signedIn($this, 'luna', Admin::owner('luna'));

    $this->visit(Admin::url('luna', '/nothing-here'))
        ->waitForText(adminLine('admin/shell.not_found.title'))
        ->assertSeeIn('.admin-page', adminLine('admin/shell.not_found.sentence'))
        ->assertCount('.admin-rail-entry', 11)
        ->assertScript("fetch(location.href, {cache: 'no-store'}).then(response => response.status)", 404);
});

it('remembers the theme on this machine across a reload', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertScript("document.documentElement.classList.contains('dark')", false)
        ->click('.admin-theme')
        ->assertScript("document.documentElement.classList.contains('dark')", true);

    $page->refresh()
        ->assertVisible(Admin::RAIL)
        ->assertScript("document.documentElement.classList.contains('dark')", true);
});

it('keeps the screen when the browser blocks site data, and loses only the theme memory', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));

    $page->script("Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('blocked', 'SecurityError'); } })");

    $page->click('.admin-theme')
        ->assertScript("document.documentElement.classList.contains('dark')", true)
        ->click('.admin-rail a[href="/admin/bookings"]')
        ->assertPathIs('/admin/bookings')
        ->assertNoJavaScriptErrors();
});

it('carries no subscription gate on a read even when the subscription has lapsed', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['subscription_state' => 'canceled']);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPathIs('/admin')
        ->assertDontSee(adminLine(RequiresActiveSubscription::DETAIL));
});

it('stands the sidebar beside the page from the wide breakpoint, 276px on a wide window, 252 on a narrower one and 104 folded, the fold remembered across a reload', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1600, 900);

    $page->assertPresent('.admin-sidebar')
        ->assertNotPresent('.admin-menu')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertScript(SHELL_SIDEBAR_WIDTH, 276);

    $page->resize(1440, 900)->assertScript(SHELL_SIDEBAR_WIDTH, 252);

    // Open, an entry shows its own words: the pointer resting on one shows no label, then or once
    // the pointer has gone and the sidebar folds.
    $page->hover('.admin-rail a[href="/admin/services"]')
        ->assertScript(SHELL_NO_TOOLTIP, true)
        ->hover('.admin-page-title');

    $page->click('.admin-sidebar-toggle')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'false')
        ->assertScript(SHELL_SIDEBAR_WIDTH, 104)
        ->assertScript("getComputedStyle(document.querySelector('.admin-rail a[href=\"/admin/bookings\"] .admin-rail-entry__label')).position", 'absolute');

    // Folded, the pointer resting on an entry shows its label beside it.
    $page->assertNotPresent('.admin-tooltip')
        ->hover('.admin-rail a[href="/admin/bookings"]')
        ->assertScript(SHELL_TOOLTIP, 'Bookings');

    $page->refresh()
        ->assertVisible(Admin::RAIL)
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'false')
        ->assertScript(SHELL_SIDEBAR_WIDTH, 104);

    $page->click('.admin-sidebar-toggle')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertScript(SHELL_SIDEBAR_WIDTH, 252);

    // From the keyboard the button keeps the focus as it folds the sidebar and as it opens it again.
    $page->keys('.admin-sidebar-toggle', 'Enter')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'false')
        ->assertScript(SHELL_FOCUS_ON_TOGGLE, true)
        ->keys('.admin-sidebar-toggle', 'Enter')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertScript(SHELL_FOCUS_ON_TOGGLE, true)
        ->assertNoJavaScriptErrors();
});

it('starts the sidebar open where the browser refuses site data, whatever was stored, and still folds and opens it', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(1440, 900);

    $page->click('.admin-sidebar-toggle')->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'false');
    $page->script("Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('blocked', 'SecurityError'); } })");

    // Below the wide breakpoint the sidebar leaves; back above it, it is built again and reads the refused storage.
    $page->resize(850, 900)->assertNotPresent('.admin-sidebar');
    $page->resize(1440, 900)
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertScript(SHELL_SIDEBAR_WIDTH, 252)
        ->click('.admin-sidebar-toggle')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'false')
        ->assertScript(SHELL_SIDEBAR_WIDTH, 104)
        ->click('.admin-sidebar-toggle')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertNoJavaScriptErrors();
});

it('cuts a sixty-character business name and user name with an ellipsis, each whole in its title, and keeps New booking on screen at 1440, at 900 and on a phone, where the two are in the drawer', function () {
    $business = 'Luna Hair Studio and Beauty Lounge of Nea Ionia, Nea Kifisia';
    $user = 'Konstantina-Alexandrina Papadopoulou-Georgiadou-Antonopoulou';
    expect(strlen($business))->toBe(60)->and(strlen($user))->toBe(60);
    DB::table('tenants')->where('slug', 'luna')->update(['name' => $business]);
    DB::table('users')->where('email', Admin::owner('luna'))->update(['name' => $user]);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));

    foreach ([1440, 900] as $width) {
        $page->resize($width, 900)
            ->assertAttribute('.admin-business', 'title', $business)
            ->assertScript(sprintf(SHELL_CUT, '.admin-business__name'), true)
            ->assertAttribute('.admin-user', 'title', $user)
            ->assertScript(sprintf(SHELL_CUT, '.admin-user'), true)
            ->assertScript(SHELL_NEW_BOOKING_INSIDE, true);
    }

    // On a phone the first row keeps its two round buttons, and the drawer holds both names.
    $page->resize(390, 844)
        ->assertScript(SHELL_ROUND_BUTTONS_INSIDE, true)
        ->assertNotPresent('.admin-user')
        ->click('.admin-menu')
        ->assertAttribute('.admin-modal__frame--drawer .admin-business', 'title', $business)
        ->assertScript(sprintf(SHELL_CUT, '.admin-modal__frame--drawer .admin-business__name'), true)
        ->assertAttribute('.admin-modal__frame--drawer .admin-user', 'title', $user)
        ->assertScript(sprintf(SHELL_CUT, '.admin-modal__frame--drawer .admin-user'), true)
        ->assertScript(PhoneScripts::DRAWER_AT_EDGE, true)
        ->assertNoJavaScriptErrors();
});

it('keeps the first row inside the window on the Calendar and Services at 900, 1024 and 1130 in English and in Greek', function (SiteLanguage $language) {
    DB::table('users')->where('email', Admin::owner('luna'))->update(['locale' => $language->value]);
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));

    foreach (['/calendar' => 'admin/calendar.page.title', '/services' => 'admin/services.page.title'] as $path => $title) {
        $page->navigate(Admin::url('luna', $path))->waitForText(adminLine($title, [], $language));

        foreach ([900, 1024, 1130] as $width) {
            $page->resize($width, 900)
                ->assertScript(SHELL_NEW_BOOKING_INSIDE, true)
                ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);
        }
    }

    $page->assertNoJavaScriptErrors();
})->with([SiteLanguage::English, SiteLanguage::Greek]);

it('shows the Services table as rows at the wide breakpoint with the sidebar open, and as the grid on a wide window', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(900, 900);

    $page->navigate(Admin::url('luna', '/services'))
        ->waitForText('Haircut')
        ->assertAttribute('.admin-sidebar-toggle', 'aria-expanded', 'true')
        ->assertScript(SHELL_SERVICES_ROWS, 'block')
        ->assertScript(PhoneScripts::NO_SIDEWAYS_SCROLL, true);

    $page->resize(1600, 900)->assertScript(SHELL_SERVICES_ROWS, 'table-row');
});

it('opens the menu as the drawer from the first row\'s Menu button below the wide breakpoint, the business and the controls in it, the first group in the bar along the foot', function () {
    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'))->resize(850, 900);

    $page->assertNotPresent('.admin-sidebar')
        ->assertScript("document.querySelector('.admin-page-header__row')?.firstElementChild?.classList.contains('admin-menu')", true)
        ->assertScript(PhoneScripts::BAR_LABELS, [navLabel('home'), navLabel('calendar'), navLabel('bookings'), navLabel('shifts'), navLabel('menu')])
        ->assertNotPresent('.admin-topbar .admin-controls')
        ->click('.admin-menu')
        ->assertScript(PhoneScripts::BAR_HIDDEN, true)
        ->assertVisible('.admin-modal__frame--drawer .admin-controls .admin-signout')
        ->assertCount('.admin-modal__frame--drawer .admin-rail-entry', 11)
        ->assertSeeIn('.admin-modal__frame--drawer .admin-business', 'Luna Hair Studio')
        ->click('.admin-modal__frame--drawer a[href="/admin/bookings"]')
        ->assertPathIs('/admin/bookings')
        ->assertNotPresent('.admin-modal__frame--drawer');

    $page->resize(1440, 900)
        ->assertPresent('.admin-sidebar')
        ->assertNotPresent('.admin-menu')
        ->assertNotPresent('.admin-bottom-bar')
        ->assertPresent('.admin-topbar .admin-controls')
        ->assertNoJavaScriptErrors();
});
