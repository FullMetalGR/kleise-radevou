<?php

use App\Http\AdminStrings;
use App\Http\ConsoleSession;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\PaymentsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Console\LoginController as ConsoleLoginController;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

pest()->use(RefreshDatabase::class);

/*
 * The admin's string transport: the strings prop holds lang/{en,el}/admin/shell.php as the
 * shell's section on every admin page, the sign-in page and its answer past the limit included,
 * and beside it each section a page's controller names on the request, joined and never replacing
 * the shell's. The console's door and shell render the same kit, so the console's adapter hands
 * the shell's section read with the locale fixed to English, the door past its limit too, and the
 * door's four framework lines read in English there; the central host's pages hand the toaster's
 * and the controls' groups.
 */

beforeEach(function () {
    $this->withoutVite();
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example']);
});

function stringsOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

it('carries the shell\'s section on every admin page, the door with nobody signed in and a page inside the shell alike', function () {
    $this->get(tenantUrl('luna', '/admin/login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('auth.user', null)->where('strings', ['shell' => __('admin/shell', [], 'en')]));

    // The page inside the shell whose controller names no section of its own: the 404 of an
    // unknown path. Every other page names its own beside the shell's.
    $this->actingAs(stringsOwner())->get(tenantUrl('luna', '/admin/nothing-here'))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('NotFound')->where('strings', ['shell' => __('admin/shell', [], 'en')]));

    $this->actingAs(stringsOwner())->get(tenantUrl('luna', '/admin/billing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Billing')->where('strings', ['shell' => __('admin/shell', [], 'en'), BillingController::SECTION => __('admin/billing', [], 'en')]));

    // Settings names four beside the shell's: its own, the payments', the billing's for the
    // Danger zone and the emails' for the Emails section.
    $this->actingAs(stringsOwner())->get(tenantUrl('luna', '/admin/settings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component(SettingsController::PAGE)->where('strings', ['shell' => __('admin/shell', [], 'en'), SettingsController::SECTION => __('admin/settings', [], 'en'), PaymentsController::SECTION => __('admin/payments', [], 'en'), BillingController::SECTION => __('admin/billing', [], 'en'), SettingsController::EMAILS => __('admin/emails', [], 'en')]));

    $this->actingAs(stringsOwner())->get(tenantUrl('luna', '/admin/reports'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports')->where('strings', ['shell' => __('admin/shell', [], 'en'), 'reports' => __('admin/reports', [], 'en')]));
});

it('hands the door past its limit the shell\'s section itself, since it answers before the adapter shares', function () {
    foreach (range(1, 10) as $attempt) {
        RateLimiter::hit(md5('login127.0.0.1'), 60);
    }

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong'])
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('language', 'en')->where('strings', ['shell' => __('admin/shell', [], 'en')]));
});

it('joins the sections a controller names to the shell\'s, beside it and never in its place, each read in the request\'s language', function () {
    app('translator')->addLines(['admin/probe.title' => 'A probe', 'admin/probe.sentence' => 'One :name.'], 'en');
    $request = Request::create(tenantUrl('luna', '/admin/probe'));
    $strings = app(AdminStrings::class);

    expect($strings->for($request))->toBe(['shell' => __('admin/shell', [], 'en')]);

    AdminStrings::need($request, 'probe');
    AdminStrings::need($request, 'probe');

    expect($strings->for($request))->toBe(['shell' => __('admin/shell', [], 'en'), 'probe' => ['title' => 'A probe', 'sentence' => 'One :name.']])
        ->and($request->attributes->get(AdminStrings::NAMED))->toBe(['probe']);
});

it('reads a controller\'s named sections when the page renders, after the controller ran', function () {
    app('translator')->addLines(['admin/probe.title' => 'A probe'], 'en');
    app('router')->matched(function (RouteMatched $event): void {
        AdminStrings::need($event->request, 'probe');
    });

    $this->actingAs(stringsOwner())->get(tenantUrl('luna', '/admin/bookings'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('strings.shell', __('admin/shell', [], 'en'))->where('strings.probe', ['title' => 'A probe']));
});

it('hands the console the shell\'s section in English whatever the application\'s locale, its door, a screen inside its shell and its door past the limit alike, with lang/el/admin/shell.php present, under lang en', function () {
    $english = __('admin/shell', [], 'en');
    $operator = addOperator();
    app()->setLocale('el');

    try {
        $door = $this->get(app(TenantUrls::class)->console('/login'))->assertOk();
        $door->assertInertia(fn (Assert $page) => $page->component('console/Login')->where('strings', ['shell' => $english]));
        $screen = $this->actingAs($operator, ConsoleSession::GUARD)->withSession(freshConsoleSession())->get(consoleUrl('/messages'))->assertOk();
        $screen->assertInertia(fn (Assert $page) => $page->where('strings', ['shell' => $english]));
        restoreWebGuard();

        foreach (range(1, 10) as $attempt) {
            RateLimiter::hit(md5('console-login127.0.0.1'), 60);
        }

        $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertInertia(fn (Assert $page) => $page->component('console/Login')->where('refusal', ConsoleLoginController::TOO_MANY)->where('strings', ['shell' => $english]));
    } finally {
        app()->setLocale('en');
    }

    expect(str_contains((string) $door->getContent(), '<html lang="en">'))->toBeTrue()
        ->and(str_contains((string) $screen->getContent(), '<html lang="en">'))->toBeTrue()
        ->and(is_file(lang_path('el/admin/shell.php')))->toBeTrue()
        ->and(__('admin/shell.kit', [], 'el'))->not->toBe(__('admin/shell.kit', [], 'en'));
});

it('words the console door\'s four framework lines as the form\'s own in English, whatever the application\'s locale, their Greek present', function () {
    app()->setLocale('el');

    try {
        $this->post(consoleUrl('/login'), [])
            ->assertSessionHasErrors(['email' => adminLine('admin/shell.validation.login.email_required'), 'password' => adminLine('admin/shell.validation.login.password_required')]);
        $this->post(consoleUrl('/login'), ['email' => ['a'], 'password' => ['b']])
            ->assertSessionHasErrors(['email' => adminLine('admin/shell.validation.login.email_string'), 'password' => adminLine('admin/shell.validation.login.password_string')]);
    } finally {
        app()->setLocale('en');
    }

    foreach (['email_required', 'email_string', 'password_required', 'password_string'] as $line) {
        expect(adminLine('admin/shell.validation.login.'.$line, [], SiteLanguage::Greek))->not->toBe(adminLine('admin/shell.validation.login.'.$line));
    }
});

it('words the admin door\'s four framework lines as the form\'s own, never the framework\'s', function () {
    $this->post(tenantUrl('luna', '/admin/login'), [])
        ->assertSessionHasErrors(['email' => adminLine('admin/shell.validation.login.email_required'), 'password' => adminLine('admin/shell.validation.login.password_required')]);

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => ['a'], 'password' => ['b']])
        ->assertSessionHasErrors(['email' => adminLine('admin/shell.validation.login.email_string'), 'password' => adminLine('admin/shell.validation.login.password_string')]);
});

it('hands the central host\'s pages the toaster\'s and the controls\' groups of the shell under shell, beside the signup\'s strings, and nothing else of it', function () {
    $this->get(centralUrl('/signup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('strings', centralStrings(SiteLanguage::English))
            ->where('strings.shell', fn (Collection $shell): bool => $shell->keys()->all() === AdminStrings::KIT));
});
