<?php

use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\Admin;

/*
 * The consent banner in a real browser: nothing of the analytics tag loads before consent,
 * Accept loads it, Reject keeps it off, and no tenant host ever loads it or shows the banner,
 * the same browser having accepted on the central host.
 *
 * The tag's address is pointed at the central host, under a path no route holds, so the request
 * Accept makes is answered by this application, the site's 404, and no test reaches Google; a
 * script answered with a 404 runs nothing. The id is a placeholder of the shape GA4 prints. What
 * was requested is read from the page's resource timing entries, as EmbedTest reads the config
 * request. The build gate wrote the consent script this suite serves.
 */

const SITE_CONSENT_ID = 'G-BROWSER123';

/** The tag's address on the central host, a path no route holds. */
function siteConsentScript(): string
{
    return app(TenantUrls::class)->central('/analytics-stub/gtag/js');
}

/** How many requests the page made to the tag's address, by its resource timing entries. */
function siteConsentRequests(): string
{
    return sprintf("performance.getEntriesByType('resource').filter((entry) => entry.name.startsWith(%s)).length", json_encode(siteConsentScript()));
}

/**
 * Resolves true once the page has requested the tag's address, false after five seconds: the
 * entry is written when the response has arrived, after the click that inserted the script.
 */
function siteConsentRequested(): string
{
    return sprintf(<<<'JS'
        new Promise((resolve) => {
            const started = Date.now();
            const check = () => {
                if (%s > 0) {
                    resolve(true);
                } else if (Date.now() - started > 5000) {
                    resolve(false);
                } else {
                    setTimeout(check, 25);
                }
            };
            check();
        })
        JS, siteConsentRequests());
}

/** Whether every resource the page loaded came from this machine: localhost or a label under it. */
const SITE_CONSENT_LOCAL = "performance.getEntriesByType('resource').every((entry) => /(^|\\.)localhost$/.test(new URL(entry.name).hostname))";

beforeEach(function () {
    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);

    config([
        'services.google_analytics.measurement_id' => SITE_CONSENT_ID,
        'services.google_analytics.script' => siteConsentScript(),
    ]);
});

afterEach(fn () => dropDemoSchemas());

it('asks before anything loads, keeps the tag off after Reject, loads it after Accept with the denied default first, and never on a tenant host', function () {
    $urls = app(TenantUrls::class);

    // A fresh browser on the home page: the banner, and no request to the tag's address.
    $page = $this->visit($urls->central('/'))
        ->assertVisible('.site-consent')
        ->assertSeeIn('.site-consent-choices', __('site.consent.accept', [], 'en'))
        ->assertSeeIn('.site-consent-choices', __('site.consent.reject', [], 'en'))
        ->assertScript(siteConsentRequests(), 0)
        ->assertScript('window.dataLayer === undefined')
        ->assertScript(SITE_CONSENT_LOCAL)
        ->assertNoJavaScriptErrors();

    // Accept and Reject weigh the same: one element, one class list, one size, side by side.
    $choices = $page->script("Array.from(document.querySelectorAll('.site-consent-choices > button')).map((button) => { const box = button.getBoundingClientRect(); return {tag: button.tagName, classes: button.className, width: Math.round(box.width), height: Math.round(box.height), top: Math.round(box.top), left: Math.round(box.left)}; })");

    assert(is_array($choices) && count($choices) === 2);
    [$accept, $reject] = array_values($choices);
    assert(is_array($accept) && is_array($reject) && is_int($accept['left']));

    expect($accept['tag'])->toBe('BUTTON')
        ->and($reject['tag'])->toBe('BUTTON')
        ->and($reject['classes'])->toBe($accept['classes'])
        ->and($reject['width'])->toBe($accept['width'])
        ->and($reject['height'])->toBe($accept['height'])
        ->and($reject['top'])->toBe($accept['top'])
        ->and($reject['left'])->toBeGreaterThan($accept['left']);

    // Reject, then a reload: no banner, and still no request.
    $page->click('[data-consent-choice="denied"]')
        ->assertMissing('.site-consent')
        ->assertScript("document.cookie.includes('analytics_consent=denied')")
        ->refresh()
        ->assertMissing('.site-consent')
        ->assertScript(siteConsentRequests(), 0)
        ->assertScript('window.dataLayer === undefined')
        ->assertNoJavaScriptErrors();

    // Cookie settings reopens it; Accept makes the request, the denied default ahead of the update.
    $page->click('.site-consent-settings')
        ->assertVisible('.site-consent')
        ->assertPathIs('/')
        ->click('[data-consent-choice="granted"]')
        ->assertMissing('.site-consent')
        ->assertScript(siteConsentRequested())
        ->assertScript(siteConsentRequests(), 1)
        ->assertScript("Array.from(document.scripts).filter((script) => script.src.startsWith('".siteConsentScript()."')).map((script) => new URL(script.src).searchParams.get('id')).join()", SITE_CONSENT_ID)
        ->assertScript("window.dataLayer.map((entry) => typeof entry[1] === 'string' ? entry[0] + ' ' + entry[1] : entry[0]).join('|')", 'consent default|consent update|js|config '.SITE_CONSENT_ID)
        ->assertScript('JSON.stringify(window.dataLayer[0][2])', json_encode(['analytics_storage' => 'denied', 'ad_storage' => 'denied', 'ad_user_data' => 'denied', 'ad_personalization' => 'denied']))
        ->assertScript('JSON.stringify(window.dataLayer[1][2])', json_encode(['analytics_storage' => 'granted']))
        ->assertScript('JSON.stringify(window.dataLayer[3][2])', json_encode(['cookie_domain' => 'none']))
        ->assertScript("document.cookie.includes('analytics_consent=granted')")
        ->assertScript(SITE_CONSENT_LOCAL)
        ->assertNoJavaScriptErrors();

    // The same browser on a tenant host: its hosted booking page, then its admin's sign-in page.
    foreach ([hostedPage('luna'), Admin::url('luna', '/login')] as $url) {
        $page->navigate($url)
            ->waitForEvent('load')
            ->assertScript('location.hostname', 'luna.localhost')
            ->assertNotPresent('.site-consent')
            ->assertNotPresent('.site-consent-settings')
            ->assertScript(siteConsentRequests(), 0)
            ->assertScript('window.dataLayer === undefined')
            ->assertScript("document.cookie.includes('analytics_consent')", false)
            ->assertScript(SITE_CONSENT_LOCAL);
    }
});

it('reloads on Reject where the tag had run, expiring its _ga cookies, and asks nothing more', function () {
    $page = $this->visit(app(TenantUrls::class)->central('/'))
        ->click('[data-consent-choice="granted"]')
        ->assertScript(siteConsentRequested())
        ->assertScript("(() => { document.cookie = '_ga=GA1.1.123.456; Path=/'; document.cookie = '_ga_BROWSER123=GS1.1.789; Path=/'; return document.cookie.includes('_ga_BROWSER123'); })()")
        ->assertScript('window.__beforeReject = true');

    $page->click('.site-consent-settings')
        ->click('[data-consent-choice="denied"]')
        ->waitForEvent('load')
        ->assertScript("performance.getEntriesByType('navigation')[0].type", 'reload')
        ->assertScript('window.__beforeReject === undefined')
        ->assertMissing('.site-consent')
        ->assertScript("document.cookie.includes('_ga')", false)
        ->assertScript("document.cookie.includes('analytics_consent=denied')")
        ->assertScript(siteConsentRequests(), 0)
        ->assertScript('window.dataLayer === undefined')
        ->assertNoJavaScriptErrors();
});
