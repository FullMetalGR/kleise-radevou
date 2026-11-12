<?php

use App\Site\DemoSalon;
use App\Tenancy\TenantUrls;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;

/*
 * The demo on the home page in a real browser: the demo built through demo:reset's seed, the home
 * page on the central host with the embed mounted from the demo's origin, and a booking made
 * through it end to end, a service, a stylist, a time, a visitor's details typed and the booking
 * confirmed, the done step shown. The demo keeps nothing a visitor typed (8.2), so the booking
 * the walk reads back holds the sample customer, DemoSalon::SAMPLE_CUSTOMER, and no row holds the
 * typed details. The embed reads no prefill, so its details step starts empty and the walk types
 * into it.
 *
 * The analytics tag is configured, so the consent banner asks, and its address is pointed at the
 * central host under a path no route holds, as 07's walk points it (SiteConsentTest): the demo's
 * embed calls the demo's host, the product itself, and needs no consent, and nothing on the way
 * requests the tag's address, read from the page's resource timing entries.
 *
 * The walk runs on the real clock, as the Browser suite does, and books a day of the second week
 * the when step shows, a Monday seven to thirteen days on, past the seed's week of bookings and
 * inside the services' horizon, so every hour of that day is free; never a spelled date. It books
 * Blow-dry, which needs no approval, so the done step reads as a confirmed booking. The bundle is
 * the one npm run build wrote, which is why the build gate precedes this one.
 */

const SITE_DEMO_ANALYTICS_ID = 'G-BROWSER456';

const SITE_DEMO_VISITOR_NAME = 'Theodora Typedname';

const SITE_DEMO_VISITOR_EMAIL = 'theodora.typed@example.test';

const SITE_DEMO_VISITOR_PHONE = '+30 210 7654321';

const SITE_DEMO_VISITOR_NOTE = 'A note the demo must not keep.';

/** Whether every resource the page loaded came from this machine: localhost or a label under it. */
const SITE_DEMO_LOCAL = "performance.getEntriesByType('resource').every((entry) => /(^|\\.)localhost$/.test(new URL(entry.name).hostname))";

/** The primary the embed's stylesheet paints the current crumb with when the mount names no theme. */
const SITE_DEMO_EMBED_PRIMARY = "getComputedStyle(document.querySelector('#demo .kr-booking__crumb[aria-current=\"step\"]')).borderBottomColor";

/** The analytics tag's address on the central host, a path no route holds. */
function siteDemoAnalytics(): string
{
    return app(TenantUrls::class)->central('/analytics-stub/gtag/js');
}

/** How many requests the page made to the tag's address, by its resource timing entries. */
function siteDemoAnalyticsRequests(): string
{
    return sprintf("performance.getEntriesByType('resource').filter((entry) => entry.name.startsWith(%s)).length", json_encode(siteDemoAnalytics()));
}

/** The origins the page's requests to a path went to, in the order they were made. */
function siteDemoOriginsOf(string $path): string
{
    return sprintf("performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname === %s).map((entry) => new URL(entry.name).origin).join('|')", json_encode($path));
}

/**
 * The demo's bookings that came through an embed, with what each holds of a customer: the seed
 * writes its week as hosted and admin bookings alone, so the walk's booking is the one row here.
 *
 * @return list<array<mixed>>
 */
function siteDemoEmbedBookings(): array
{
    $bookings = [];

    foreach (DB::select(sprintf(
        'select b.id, b.status, b.hold_class, b.source, b.customer_name, b.customer_email, b.customer_phone, b.total_minor, b.currency,'
        ." (select count(*) from %1\$s.booking_meta m where m.booking_id = b.id and m.key = 'note') as notes"
        ." from %1\$s.bookings b where b.source = 'embed' order by b.id",
        siteDemoSlug()->schemaName(),
    )) as $row) {
        assert($row instanceof stdClass);

        $bookings[] = (array) $row;
    }

    return $bookings;
}

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.')
        ->and(Artisan::call('demo:reset'))->toBe(0);

    config([
        'services.google_analytics.measurement_id' => SITE_DEMO_ANALYTICS_ID,
        'services.google_analytics.script' => siteDemoAnalytics(),
    ]);
});

afterEach(fn () => dropSiteDemo());

it('books on the demo through the embed on the home page, the booking holding the sample customer and none of the typed details, with no request to the analytics address', function () {
    $urls = app(TenantUrls::class);
    $demo = siteDemoSlug();
    $monday = Flow::day(DemoSalon::ZONE, 1);
    $start = Flow::start($monday, 9);

    // The home page on the central host, the banner asking, and the embed mounted in the demo
    // section from the demo's origin: its script, then its config fetched across origins.
    $page = $this->visit($urls->central('/'))
        ->assertScript('location.hostname', 'localhost')
        ->assertVisible('.site-consent')
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertCount('.kr-booking__frame', 1)
        ->assertScript("document.querySelector('#demo .kr-booking__frame') !== null", true)
        ->assertScript("Array.from(document.scripts).filter((script) => script.src.endsWith('/embed.js')).map((script) => script.src).join('|')", $urls->embedScript($demo))
        ->assertScript(siteDemoOriginsOf('/embed.js'), $urls->origin($demo))
        ->assertScript(siteDemoOriginsOf('/api/v1/config'), $urls->origin($demo))
        ->assertSeeIn('#demo .kr-booking', DemoSalon::NAME)
        // Containment on the site's page: the embed's own stylesheet beside the site's one link,
        // and the embed's default, neutral's primary, not the site's, on its current crumb.
        ->assertCount('style[data-kr-stylesheet]', 1)
        ->assertCount('link[rel="stylesheet"]', 1)
        ->assertScript(SITE_DEMO_EMBED_PRIMARY, 'rgb(23, 23, 23)')
        ->click('Blow-dry')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click('Katerina Nikolaou')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(sprintf("document.querySelector('.kr-booking__slot[value=\"%s\"]') !== null", $start), true)
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        // The embed reads no prefill: the details step starts empty, on the home page as on any.
        ->assertValue('customer_name', '')
        ->assertValue('customer_email', '')
        ->type('customer_name', SITE_DEMO_VISITOR_NAME)
        ->type('customer_email', SITE_DEMO_VISITOR_EMAIL)
        ->type('customer_phone', SITE_DEMO_VISITOR_PHONE)
        ->type('note', SITE_DEMO_VISITOR_NOTE)
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary-service', 'Blow-dry')
        ->assertSeeIn('.kr-booking__summary-staff', 'Katerina Nikolaou')
        ->assertSeeIn('.kr-booking__total', '15.00')
        ->assertSeeIn('.kr-booking__important', Flow::said('review.important_info'))
        ->assertDontSee(Flow::said('review.pay'))
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertScript(FlowScripts::saidPolitely(Flow::said(Flow::CONFIRMED)), true)
        ->assertSee(Flow::said(Flow::CONFIRMED))
        ->assertDontSee(Flow::said('done.headings.awaiting_approval'))
        // Nothing reads like the token, and a failure says where it was: the requests first,
        // named by their paths, then the markup.
        ->assertScript(FlowScripts::TOKEN_URLS, '[]')
        ->assertScript(FlowScripts::TOKEN_IN_MARKUP, false)
        // Nothing on the way asked for the tag: the banner still unanswered, no request to its
        // address and no data layer, every resource from this machine.
        ->assertVisible('.site-consent')
        ->assertScript(siteDemoAnalyticsRequests(), 0)
        ->assertScript('window.dataLayer === undefined')
        ->assertScript("document.cookie.includes('analytics_consent')", false)
        ->assertScript(SITE_DEMO_LOCAL)
        ->assertNoJavaScriptErrors();

    $bookings = siteDemoEmbedBookings();
    $typed = DB::table($demo->schemaName().'.bookings')
        ->where('customer_name', SITE_DEMO_VISITOR_NAME)
        ->orWhere('customer_email', SITE_DEMO_VISITOR_EMAIL)
        ->orWhere('customer_phone', SITE_DEMO_VISITOR_PHONE)
        ->count();
    $id = $bookings[0]['id'] ?? null;

    expect($bookings)->toHaveCount(1)
        ->and($bookings[0] ?? null)->toMatchArray([
            'status' => 'confirmed',
            'hold_class' => null,
            'source' => 'embed',
            'customer_name' => DemoSalon::SAMPLE_CUSTOMER['name'],
            'customer_email' => DemoSalon::SAMPLE_CUSTOMER['email'],
            'customer_phone' => DemoSalon::SAMPLE_CUSTOMER['phone'],
            'total_minor' => 1500,
            'currency' => DemoSalon::CURRENCY,
            'notes' => 0,
        ])
        ->and($typed)->toBe(0)
        ->and(DB::table($demo->schemaName().'.booking_meta')->where('value', SITE_DEMO_VISITOR_NOTE)->count())->toBe(0)
        ->and(DB::table($demo->schemaName().'.audit_log')->where('booking_id', $id)->orderBy('id')->pluck('action')->all())->toBe(['held', 'confirmed']);
});
