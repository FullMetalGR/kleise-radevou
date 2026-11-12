<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\ForeignSite;

/*
 * The embed shell mounts on a page that is not ours. A static site on a second loopback
 * origin carries the exact snippet the provisioning command prints, and a real browser loads
 * the bundle from the tenant origin, fetches the config across origins, and shows the shell:
 * once per mount element, once however many times the tag is pasted. The hosted page runs the
 * same bundle off its inlined config and asks for no config at all. The bundle is the one npm
 * run build wrote, which is why the build gate precedes this one.
 */

/**
 * Resolves true once no frame on the page is still loading, so the assertions that follow
 * read a settled shell; false after five seconds, which is a failure worth reading.
 */
const SHELL_SETTLED = <<<'JS'
    new Promise((resolve) => {
        const started = Date.now();
        const check = () => {
            const frames = Array.from(document.querySelectorAll('.kr-booking__frame'));
            if (frames.length > 0 && frames.every((frame) => frame.getAttribute('data-kr-state') !== 'loading')) {
                resolve(true);
            } else if (Date.now() - started > 5000) {
                resolve(false);
            } else {
                setTimeout(check, 25);
            }
        };
        check();
    })
    JS;

const CONFIG_REQUESTS = "performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname === '/api/v1/config').length";

/**
 * The theme's primary as the stylesheet paints it: the underline under the crumb's current step, which
 * reads the theming property the mount element carries.
 */
const EMBED_PRIMARY = "getComputedStyle(document.querySelector('.kr-booking__crumb[aria-current=\"step\"]')).borderBottomColor";

/**
 * Whether the summary card stands beside the step, sticky, on a page that gives the mount its
 * whole width: the frame holds the step's 480px, the gap's 40 and the card's 360.
 */
const EMBED_CARD_BESIDE = "(() => { const frame = document.querySelector('.kr-booking__frame').getBoundingClientRect(); const main = document.querySelector('.kr-booking__main').getBoundingClientRect(); const card = document.querySelector('.kr-booking__estimate'); return frame.width >= 880 && getComputedStyle(card).position === 'sticky' && card.getBoundingClientRect().left >= main.right; })()";

function snippetFor(string $slug): string
{
    return app(TenantUrls::class)->embedSnippet(Slug::of($slug));
}

function tenantOrigin(string $slug): string
{
    return app(TenantUrls::class)->origin(Slug::of($slug));
}

/**
 * A page of somebody else's, with a stylesheet of its own, holding the snippet as pasted.
 */
function foreignPage(string $name, string $body): string
{
    return ForeignSite::current()->page($name, <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <title>Somebody else's site</title>
            <style>p { color: rgb(255, 0, 0); } h2 { font-style: italic; }</style>
        </head>
        <body>
            <h1>A page that is not ours</h1>
            {$body}
        </body>
        </html>
        HTML);
}

beforeAll(fn () => ForeignSite::start());
afterAll(fn () => ForeignSite::stop());

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('mounts the branded shell on a page served from another origin', function () {
    $page = foreignPage('one.html', snippetFor('luna'));

    $this->visit($page)
        ->assertScript(SHELL_SETTLED, true)
        ->assertScript('location.origin', ForeignSite::current()->origin)
        ->assertScript("location.origin !== '".tenantOrigin('luna')."'")
        ->assertCount('.kr-booking__frame', 1)
        ->assertSeeIn('.kr-booking', 'Luna Hair Studio')
        ->assertDontSee('Pier Physiotherapy')
        ->assertAttribute('.kr-booking__frame', 'data-kr-state', 'ready')
        ->assertAttribute('.kr-booking__frame', 'data-kr-step', 'service')
        ->assertScript(CONFIG_REQUESTS, 1)
        ->assertScript(EMBED_PRIMARY, 'rgb(23, 23, 23)')
        ->assertScript(EMBED_CARD_BESIDE, true)
        ->assertScript("getComputedStyle(document.querySelector('.kr-booking__notice')).color", 'rgb(255, 0, 0)')
        ->assertScript("document.querySelectorAll('script[type=\"application/ld+json\"], .hosted-profile').length", 0)
        ->assertNoJavaScriptErrors();
});

it('mounts one shell per mount element: two on a page with two', function () {
    $page = foreignPage('two.html', '<div class="kr-booking"></div><p>Between the two.</p>'.snippetFor('luna'));

    $this->visit($page)
        ->assertScript(SHELL_SETTLED, true)
        ->assertCount('.kr-booking', 2)
        ->assertCount('.kr-booking__frame', 2)
        ->assertCount('.kr-booking__title', 2)
        ->assertScript("Array.from(document.querySelectorAll('.kr-booking__title')).map((title) => title.textContent).join('|')", 'Select services|Select services')
        ->assertScript("Array.from(document.querySelectorAll('.kr-booking__estimate-business')).map((name) => name.textContent).join('|')", 'Luna Hair Studio|Luna Hair Studio')
        ->assertScript(CONFIG_REQUESTS, 1)
        ->assertNoJavaScriptErrors();
});

it('mounts once when the script tag is pasted twice', function () {
    $snippet = snippetFor('luna');
    $tag = substr($snippet, (int) strpos($snippet, '<script'));
    $page = foreignPage('twice.html', $snippet."\n".$tag);

    $this->visit($page)
        ->assertScript(SHELL_SETTLED, true)
        ->assertCount('script[src$="/embed.js"]', 2)
        ->assertCount('.kr-booking', 1)
        ->assertCount('.kr-booking__frame', 1)
        ->assertCount('.kr-booking__title', 1)
        ->assertCount('style[data-kr-stylesheet]', 1)
        ->assertScript('window.__krMounted instanceof WeakSet')
        ->assertScript(CONFIG_REQUESTS, 1)
        ->assertNoJavaScriptErrors();
});

it('mounts once on the hosted page off the inlined config, with no config request, under the tenant theme', function () {
    $this->visit(hostedPage('luna'))
        ->assertScript(SHELL_SETTLED, true)
        ->assertCount('.kr-booking__frame', 1)
        ->assertSeeIn('.kr-booking', 'Luna Hair Studio')
        ->assertAttribute('.kr-booking__frame', 'data-kr-step', 'service')
        ->assertScript("document.querySelector('.kr-booking noscript') === null")
        ->assertScript(CONFIG_REQUESTS, 0)
        ->assertScript("performance.getEntriesByType('resource').filter((entry) => entry.name.endsWith('/embed.js')).length", 1)
        ->assertScript(EMBED_PRIMARY, 'rgb(124, 58, 237)')
        ->assertNoJavaScriptErrors();

    $this->visit(hostedPage('pier'))
        ->assertScript(SHELL_SETTLED, true)
        ->assertSeeIn('.kr-booking', 'Pier Physiotherapy')
        ->assertDontSee('Luna Hair Studio')
        ->assertScript(CONFIG_REQUESTS, 0)
        ->assertScript(EMBED_PRIMARY, 'rgb(37, 99, 235)');
});
