<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Database\Seeders\DemoTenantsSeeder;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Browser\Support\ForeignSite;

/*
 * The first real steps of the booking flow on real data, both surfaces: luna's hosted page lists
 * its seeded services and never the inactive one; choosing one and pressing the button that
 * continues reaches the staff step naming both people; on pier, whose services sit on the implicit
 * member alone, the same reaches when directly; and the embed on a foreign origin shows the same
 * list as the hosted page. The bundle is the one npm run build wrote, which is why the build gate
 * precedes this one.
 */

beforeAll(fn () => ForeignSite::start());
afterAll(fn () => ForeignSite::stop());

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);
});

afterEach(fn () => dropDemoSchemas());

it('lists the seeded services on the hosted page, in order, with the inactive one absent, and steals no focus', function () {
    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::STEP, 'service')
        ->assertScript(FlowScripts::SERVICE_NAMES, 'Haircut|Beard trim|Colour|Bridal trial')
        ->assertDontSee('Perm')
        ->assertSeeIn('.kr-booking', '45 min')
        ->assertSeeIn('.kr-booking', '35.00')
        ->assertSeeIn('.kr-booking', 'Consultation, wash, cut and finish.')
        ->assertScript("document.querySelectorAll('.kr-booking__service').length", 4)
        ->assertScript("document.querySelectorAll('.kr-booking__service[aria-pressed=\"false\"]').length", 4)
        ->assertScript("document.querySelectorAll('.kr-booking__service-text > span').length", 16)
        ->assertScript('document.activeElement === document.body')
        ->assertNoJavaScriptErrors();
});

it('reaches the staff step naming both people after choosing a service and pressing the button that continues on luna', function () {
    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::CONTINUE_DISABLED, true)
        ->click('Haircut')
        ->assertScript(FlowScripts::STEP, 'service')
        ->assertScript("document.querySelector('.kr-booking__service[aria-pressed=\"true\"] .kr-booking__service-name').textContent", 'Haircut')
        ->assertSeeIn('.kr-booking__estimate-name', 'Haircut')
        ->assertScript(FlowScripts::CONTINUE_DISABLED, false)
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertSeeIn('.kr-booking__subtitle', 'Haircut')
        ->assertScript(FlowScripts::CHOICES, sprintf('%s %s:true|Elena Papadaki:false|Nikos Georgiou:false', Flow::said('staff.any'), Flow::said('staff.any_line')))
        // The business page's team above the flow names the same people, so a click by the name
        // would land there; the third choice of the picker is Nikos's.
        ->click('.kr-booking__staff .kr-booking__item:nth-child(3) .kr-booking__choice')
        ->assertScript(FlowScripts::CHOICES, sprintf('%s %s:false|Elena Papadaki:false|Nikos Georgiou:true', Flow::said('staff.any'), Flow::said('staff.any_line')))
        ->click('.kr-booking__back')
        ->assertScript(FlowScripts::STEP, 'service')
        ->assertSeeIn('.kr-booking__estimate-name', 'Haircut')
        ->assertNoJavaScriptErrors();
});

it('reaches when directly on pier, whose services list nobody', function () {
    $this->visit(hostedPage('pier'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::SERVICE_NAMES, 'Initial assessment|Follow-up session')
        ->assertSeeIn('.kr-booking', '65.00')
        ->click('Initial assessment')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when')
        ->assertScript(FlowScripts::FOCUSED_FRAME)
        ->assertScript("document.querySelectorAll('.kr-booking__choice').length", 0)
        ->assertScript("document.querySelector('.kr-booking__back').getAttribute('aria-label')", Flow::said('strip.back'))
        ->assertNoJavaScriptErrors();
});

it('shows the same list on the embed at a foreign origin as on the hosted page', function () {
    $hosted = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->script(FlowScripts::SERVICE_NAMES);

    $snippet = app(TenantUrls::class)->embedSnippet(Slug::of('luna'));
    $page = ForeignSite::current()->page('catalog.html', <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Somebody else's site</title></head>
        <body>
            <h1>A page that is not ours</h1>
            {$snippet}
        </body>
        </html>
        HTML);

    $this->visit($page)
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript("location.origin !== '".app(TenantUrls::class)->origin(Slug::of('luna'))."'")
        ->assertScript(FlowScripts::SERVICE_NAMES, $hosted)
        ->assertScript(FlowScripts::SERVICE_NAMES, 'Haircut|Beard trim|Colour|Bridal trial')
        ->assertScript("performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname === '/api/v1/services').length", 1)
        ->assertDontSee('Perm')
        ->click('Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->assertScript(FlowScripts::CHOICES, sprintf('%s %s:true|Elena Papadaki:false|Nikos Georgiou:false', Flow::said('staff.any'), Flow::said('staff.any_line')))
        ->assertNoJavaScriptErrors();
});
