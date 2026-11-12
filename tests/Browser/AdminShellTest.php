<?php

use App\Http\Controllers\Admin\LoginController;
use App\Http\Middleware\RequiresActiveSubscription;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\FlowScripts;

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
