<?php

use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Console;

/*
 * The provisional brand in a real browser: the site's pages and the signup's frame carry the
 * wordmark and are set in the brand's type and colours; the console's shell and door wear the
 * brand's scope, the admin's primary taking the brand's; and the admin keeps its own theme, a
 * small wordmark in its top bar the one thing of the brand it carries. Every name is the
 * configured public name. The build gate wrote the stylesheets this suite serves.
 */

// The brand's primary as a computed colour; brand.css spells it #1d5b8f.
const BRAND_PRIMARY_RGB = 'rgb(29, 91, 143)';

// A custom property's computed value on the first element a selector names.
const BRAND_PROPERTY = "getComputedStyle(document.querySelector('%s')).getPropertyValue('%s').trim()";

it('sets the site in the brand: the wordmark in the header naming the public name, the system type and the primary on the 404\'s button', function () {
    $urls = app(TenantUrls::class);

    $this->visit($urls->central('/'))
        ->assertSeeIn('header .site-name .brand-wordmark__name', siteName())
        ->assertPresent('header .brand-wordmark__mark svg.brand-mark')
        ->assertScript("document.querySelector('link[rel=\"icon\"]').getAttribute('href')", '/favicon.svg')
        ->assertScript("getComputedStyle(document.body).fontFamily.startsWith('system-ui')", true)
        ->assertNoJavaScriptErrors();

    $this->visit($urls->central('/nowhere'))
        ->assertScript("getComputedStyle(document.querySelector('main .site-button')).backgroundColor", BRAND_PRIMARY_RGB)
        ->assertScript("getComputedStyle(document.querySelector('main .site-button')).color", 'rgb(255, 255, 255)');
});

it('frames the signup in the site\'s header and footer, its own controls the admin\'s primitives in the brand\'s primary', function () {
    $this->visit(app(TenantUrls::class)->central('/signup'))
        ->waitForText('Start your booking page')
        ->assertSeeIn('body > header .brand-wordmark__name', siteName())
        ->assertSeeIn('body > footer', siteName())
        ->assertPresent('body > .site-frame-page > #app .brand-scope .admin-signup')
        ->assertTitle('Sign up - '.siteName())
        ->assertScript("getComputedStyle(document.querySelector('.admin-signup-submit')).backgroundColor", BRAND_PRIMARY_RGB)
        ->assertNoJavaScriptErrors();
});

it('wears the brand on the console\'s door and shell, the wordmark naming the public name in place of the console\'s', function () {
    expect(Artisan::call('console:add-operator', ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => 'operator password']))->toBe(0);

    $this->visit(Console::url('/login'))
        ->waitForText(adminLine('admin/shell.login.submit'))
        ->assertSeeIn('.admin-console-login-name .brand-wordmark__name', siteName())
        ->assertSeeIn('.admin-console-login-name .admin-console-label', 'console')
        ->assertScript("getComputedStyle(document.querySelector('.admin-console-login-submit')).backgroundColor", BRAND_PRIMARY_RGB);

    Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('No tenants yet')
        ->assertSeeIn('.admin-topbar .admin-business .brand-wordmark__name', siteName())
        ->assertPresent('.admin-shell.brand-scope')
        ->assertScript(sprintf(BRAND_PROPERTY, '.admin-shell', '--primary'), '#1d5b8f')
        ->assertTitle('Tenants - '.siteName())
        ->assertNoJavaScriptErrors();
});

it('keeps the admin\'s own theme, a small wordmark beside the business\'s name the one thing of the brand in it', function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertSeeIn('.admin-topbar .brand-wordmark--small .brand-wordmark__name', siteName())
        ->assertSeeIn('.admin-business', 'Luna Hair Studio')
        ->assertNotPresent('.brand-scope')
        // The tenant's own theme, written on the body (luna is seeded violet), and not the brand's.
        ->assertScript(sprintf(BRAND_PROPERTY, '.admin-shell', '--primary').' === '.sprintf(BRAND_PROPERTY, 'body', '--primary'), true)
        ->assertScript("getComputedStyle(document.querySelector('.admin-new-booking')).backgroundColor === '".BRAND_PRIMARY_RGB."'", false)
        ->assertTitle(adminLine('admin/home.page_title').' - '.siteName())
        ->assertNoJavaScriptErrors();

    dropDemoSchemas();
});
