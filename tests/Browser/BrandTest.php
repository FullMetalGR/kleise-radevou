<?php

use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Console;
use Tests\Browser\Support\Look;

/*
 * The provisional brand in a real browser: the site's pages and the signup's frame carry the
 * wordmark and are set in the brand's type and colours; the console's shell and door wear the
 * brand's scope, the admin's primary taking the brand's; and the admin keeps its own theme, the
 * platform's mark with the site's name on its sidebar the one thing of the brand it carries. Every
 * name is the configured public name. The build gate wrote the stylesheets this suite serves.
 * The console's body wears the scope too, so the menus and dialogs it opens in the body take the
 * brand's primary, whatever admin theme the body might also wear.
 *
 * The type splits along the same line: the site and the signup are set in the brand's system
 * stack and name neither of the admin's faces, while the admin and the console, its door and its
 * shell inside the brand's scope included, are set in the admin's text face with its headings in
 * the display face.
 */

// The brand's primary as a computed colour; brand.css spells it #1d5b8f.
const BRAND_PRIMARY_RGB = 'rgb(29, 91, 143)';

// A custom property's computed value on the first element a selector names.
const BRAND_PROPERTY = "getComputedStyle(document.querySelector('%s')).getPropertyValue('%s').trim()";

// The admin's primary inside the first element a selector names, as the screen shows it: a probe
// placed there paints its ground with the token, so a colour the stylesheet writes in any form
// reads back as rgb.
const BRAND_PRIMARY_PAINTED = "(() => { const probe = document.createElement('span'); probe.style.backgroundColor = 'var(--primary)'; document.querySelector('%s').appendChild(probe); const colour = getComputedStyle(probe).backgroundColor; probe.remove(); return colour; })()";

// Whether the first element a selector names is set first in the face given, which the browser
// writes quoted at the head of the computed list.
const BRAND_FACE_FIRST = "getComputedStyle(document.querySelector('%s')).fontFamily.startsWith('\"%s\"')";

// Whether the computed list of the first element a selector names names either of the admin's faces.
const BRAND_ADMIN_FACE_NAMED = "/Inter|Geologica/.test(getComputedStyle(document.querySelector('%s')).fontFamily)";

it('sets the site in the brand: the wordmark in the header naming the public name, the system type and the primary on the 404\'s button', function () {
    $urls = app(TenantUrls::class);

    $this->visit($urls->central('/'))
        ->assertSeeIn('header .site-name .brand-wordmark__name', siteName())
        ->assertPresent('header .brand-wordmark__mark svg.brand-mark')
        ->assertScript("document.querySelector('link[rel=\"icon\"]').getAttribute('href')", '/favicon.svg')
        ->assertScript("getComputedStyle(document.body).fontFamily.startsWith('system-ui')", true)
        ->assertScript(sprintf(BRAND_ADMIN_FACE_NAMED, 'body'), false)
        ->assertNoJavaScriptErrors();

    $this->visit($urls->central('/nowhere'))
        ->assertScript("getComputedStyle(document.querySelector('main .site-button')).backgroundColor", BRAND_PRIMARY_RGB)
        ->assertScript("getComputedStyle(document.querySelector('main .site-button')).color", 'rgb(255, 255, 255)');
});

it('frames the signup in the site\'s header and footer, its own controls the admin\'s primitives in the brand\'s primary, all of it in the system type', function () {
    $this->visit(app(TenantUrls::class)->central('/signup'))
        ->waitForText('Start your booking page')
        ->assertSeeIn('body > header .brand-wordmark__name', siteName())
        ->assertSeeIn('body > footer', siteName())
        ->assertPresent('body > .site-frame-page > #app .brand-scope .admin-signup')
        ->assertTitle('Sign up - '.siteName())
        ->assertScript("getComputedStyle(document.querySelector('.admin-signup-submit')).backgroundColor", BRAND_PRIMARY_RGB)
        ->assertScript("getComputedStyle(document.body).fontFamily.startsWith('system-ui')", true)
        ->assertScript(sprintf(BRAND_ADMIN_FACE_NAMED, 'body'), false)
        ->assertScript(sprintf(BRAND_ADMIN_FACE_NAMED, '.admin-signup'), false)
        ->assertScript(sprintf(BRAND_ADMIN_FACE_NAMED, '.admin-signup h1'), false)
        ->assertNoJavaScriptErrors();
});

it('wears the brand on the console\'s door and shell, the wordmark naming the public name in place of the console\'s, the text in the admin\'s faces', function () {
    expect(Artisan::call('console:add-operator', ['--name' => 'Pat Operator', '--email' => 'pat@inbox.example', '--password' => 'operator password']))->toBe(0);

    $this->visit(Console::url('/login'))
        ->waitForText(adminLine('admin/shell.login.submit'))
        ->assertSeeIn('.admin-console-login-name .brand-wordmark__name', siteName())
        ->assertSeeIn('.admin-console-login-name .admin-console-label', 'console')
        ->assertScript("getComputedStyle(document.querySelector('.admin-console-login-submit')).backgroundColor", BRAND_PRIMARY_RGB)
        ->assertScript(sprintf(BRAND_FACE_FIRST, 'body', 'Inter Variable'), true)
        ->assertScript(sprintf(BRAND_FACE_FIRST, '.admin-console-login-submit', 'Inter Variable'), true);

    Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('No tenants yet')
        ->assertSeeIn('.admin-sidebar .admin-console-wordmark .brand-wordmark__name', siteName())
        ->assertPresent('.admin-shell.brand-scope')
        ->assertScript(sprintf(BRAND_PROPERTY, '.admin-shell', '--primary'), '#1d5b8f')
        ->assertScript(sprintf(BRAND_FACE_FIRST, 'body', 'Inter Variable'), true)
        ->assertScript(sprintf(BRAND_FACE_FIRST, '.admin-shell', 'Inter Variable'), true)
        ->assertScript(sprintf(BRAND_FACE_FIRST, '.admin-shell h1', 'Geologica Variable'), true)
        ->assertTitle('Tenants - '.siteName())
        ->assertNoJavaScriptErrors();
});

it('wears the brand\'s primary over the console\'s whole page, its door, its overlays and every admin theme worn on its body included, and mixes the scope\'s tints from it', function () {
    addOperator();

    $this->visit(Console::url('/login'))
        ->waitForText(adminLine('admin/shell.login.submit'))
        ->assertScript(sprintf(BRAND_PRIMARY_PAINTED, 'body'), BRAND_PRIMARY_RGB);

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('No tenants yet')
        ->assertScript(sprintf(BRAND_PRIMARY_PAINTED, 'body'), BRAND_PRIMARY_RGB)
        ->assertScript(sprintf(BRAND_PRIMARY_PAINTED, '.admin-shell'), BRAND_PRIMARY_RGB)
        ->assertScript(sprintf(BRAND_PROPERTY, '.brand-scope', '--muted')." !== getComputedStyle(document.documentElement).getPropertyValue('--muted').trim()", true);

    foreach (Look::ALL_THEMES as $theme) {
        $page->assertScript(Look::wear($theme, false), $theme)
            ->assertScript(sprintf(BRAND_PRIMARY_PAINTED, 'body'), BRAND_PRIMARY_RGB)
            ->assertScript(sprintf(BRAND_PRIMARY_PAINTED, '.admin-shell'), BRAND_PRIMARY_RGB);
    }

    $page->click('.admin-tenants-from')
        ->assertVisible('.admin-date-field__popover')
        ->assertScript(sprintf(BRAND_PRIMARY_PAINTED, '.admin-date-field__popover'), BRAND_PRIMARY_RGB)
        ->assertNoJavaScriptErrors();
});

it('keeps the admin\'s own theme, the platform\'s mark above the business\'s name the one thing of the brand in it, the text in the admin\'s faces', function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);

    Admin::signedIn($this, 'luna', Admin::owner('luna'))
        ->assertPresent('.admin-sidebar .admin-mark img[alt=""]')
        ->assertSeeIn('.admin-sidebar .admin-mark .admin-mark__name', siteName())
        ->assertNotPresent('.admin-sidebar .brand-wordmark')
        ->assertSeeIn('.admin-business', 'Luna Hair Studio')
        ->assertNotPresent('.brand-scope')
        // The tenant's own theme, written on the body (luna is seeded violet), and not the brand's.
        ->assertScript(sprintf(BRAND_PROPERTY, '.admin-shell', '--primary').' === '.sprintf(BRAND_PROPERTY, 'body', '--primary'), true)
        ->assertScript("getComputedStyle(document.querySelector('.admin-new-booking')).backgroundColor === '".BRAND_PRIMARY_RGB."'", false)
        ->assertScript(sprintf(BRAND_FACE_FIRST, 'body', 'Inter Variable'), true)
        ->assertScript(sprintf(BRAND_FACE_FIRST, 'h1', 'Geologica Variable'), true)
        ->assertTitle(adminLine('admin/home.page_title').' - '.siteName())
        ->assertNoJavaScriptErrors();

    dropDemoSchemas();
});
