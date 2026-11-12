<?php

use App\Tenancy\TenantUrls;
use Illuminate\Support\Facades\Artisan;
use Tests\Browser\Support\FlowScripts;

/*
 * The site's rendering in a real browser: the page is Blade rendered by the server, styled by the
 * site's own stylesheet, an entry of the admin build linked through its manifest, and it loads no
 * script of its own. The build gate wrote the stylesheet this suite serves. The plugin cannot
 * switch JavaScript off; the page read with none is the feature walk's, CentralSurfaceTest.
 *
 * The home page's one script is the demo's embed, from the demo's origin, so there the demo is
 * built and the document's one script element is the embed's, and every script and fetch the
 * browser made went to the demo's origin, the bundle and its config among them; the embed's
 * stylesheet is the bundle's own, injected as a style element, so the site's one link stays the
 * one. A page but the home, the features page here, holds no script element and the browser fetched
 * none; every page's script elements are pinned with no script run by its own feature case.
 */

/** The script and fetch requests the page made, as resource timing entries. */
const SITE_SHEET_SCRIPT_REQUESTS = "performance.getEntriesByType('resource').filter((entry) => ['script', 'fetch', 'xmlhttprequest'].includes(entry.initiatorType) || entry.name.endsWith('.js'))";

afterEach(fn () => dropSiteDemo());

it('links the site\'s stylesheet from the admin build, the home styled and in full, its one script the demo\'s embed and every script and fetch it made to the demo\'s origin', function (string $path, string $language) {
    expect(Artisan::call('demo:reset'))->toBe(0);

    $urls = app(TenantUrls::class);
    $demo = $urls->origin(siteDemoSlug());
    $page = $this->visit($urls->central($path));

    $page->assertSee(__('home.heading', [], $language))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertNoJavaScriptErrors();

    $sheets = $page->script("Array.from(document.querySelectorAll('link[rel=\"stylesheet\"]')).map((link) => new URL(link.href).pathname)");

    assert(is_array($sheets));

    expect($sheets)->toHaveCount(1)
        ->and($sheets[0] ?? null)->toBeString()->toStartWith('/build/admin/assets/site-')->toEndWith('.css')
        ->and($page->script('document.documentElement.lang'))->toBe($language)
        ->and($page->script("getComputedStyle(document.querySelector('main')).maxWidth"))->toBe('1024px')
        ->and($page->script('Array.from(document.scripts).map((script) => script.src)'))->toBe([$urls->embedScript(siteDemoSlug())])
        ->and($page->script('[...new Set('.SITE_SHEET_SCRIPT_REQUESTS.'.map((entry) => new URL(entry.name).origin))]'))->toBe([$demo])
        ->and($page->script(SITE_SHEET_SCRIPT_REQUESTS.'.map((entry) => new URL(entry.name).pathname)'))->toContain('/embed.js', '/api/v1/config');
})->with([
    'English' => ['/', 'en'],
    'Greek' => ['/el', 'el'],
]);

it('links the site\'s stylesheet from the admin build on a page but the home, the features page, which loads no script', function (string $path, string $language) {
    $page = $this->visit(app(TenantUrls::class)->central($path));

    $page->assertSee(__('features.heading', [], $language))
        ->assertNoJavaScriptErrors();

    $sheets = $page->script("Array.from(document.querySelectorAll('link[rel=\"stylesheet\"]')).map((link) => new URL(link.href).pathname)");

    assert(is_array($sheets));

    expect($sheets)->toHaveCount(1)
        ->and($sheets[0] ?? null)->toBeString()->toStartWith('/build/admin/assets/site-')->toEndWith('.css')
        ->and($page->script('document.documentElement.lang'))->toBe($language)
        ->and($page->script('document.scripts.length'))->toBe(0)
        ->and($page->script("performance.getEntriesByType('resource').filter((entry) => entry.initiatorType === 'script' || entry.name.endsWith('.js')).length"))->toBe(0);
})->with([
    'English, the features page' => ['/features', 'en'],
    'Greek, the features page' => ['/el/features', 'el'],
]);
