<?php

use Database\Seeders\DemoTenantsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Admin;
use Tests\Browser\Support\Flow;
use Tests\Browser\Support\FlowScripts;
use Tests\Support\LunaFixture;

/*
 * Service categories and the online flag in a real browser: luna's four active services each
 * under a category of their own, so the hosted page heads its list four times with a jump chip
 * per heading above it; a chip moves focus to its heading; a booking goes through under one of
 * them. A fifth service, offered on site only and marked not online, is absent from the page and
 * still offered by the admin's wizard. On the system clock, its day relative to today, as every
 * walk that books is.
 */

const CATEGORY_WALK_OFFLINE = 'Express fringe';

beforeEach(function () {
    expect(is_file(public_path('build/embed.js')))->toBeTrue('public/build/embed.js is missing; run npm run build before the browser gate.');

    dropDemoSchemas();
    $this->seed(DemoTenantsSeeder::class);

    foreach (['Haircut' => 'Cuts', 'Beard trim' => 'Grooming', 'Colour' => 'Colour', 'Bridal trial' => 'Occasions'] as $service => $category) {
        DB::table('tenant_luna.services')->where('id', LunaFixture::id('services', $service))->update(['category' => $category]);
    }

    $fringe = DB::table('tenant_luna.services')->insertGetId(serviceRow(CATEGORY_WALK_OFFLINE, ['category' => 'Cuts', 'online' => false, 'sort' => 99]));
    DB::table('tenant_luna.service_staff')->insert(['service_id' => $fringe, 'staff_id' => LunaFixture::id('staff', 'Elena Papadaki')]);
});

afterEach(fn () => dropDemoSchemas());

it('heads the hosted page\'s list by its four categories with a chip per heading, moves focus to the heading a chip names, and books through a service under one', function () {
    $monday = Flow::day('Europe/Athens', 1);
    $start = Flow::start($monday, 14);
    $headings = "Array.from(document.querySelectorAll('.kr-booking__group-heading')).map((heading) => heading.textContent).join('|')";
    $chips = "Array.from(document.querySelectorAll('.kr-booking__chip')).map((chip) => chip.textContent).join('|')";

    $page = $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertScript(FlowScripts::STEP, 'service')
        ->assertScript($headings, 'Cuts|Grooming|Colour|Occasions')
        ->assertScript($chips, 'Cuts|Grooming|Colour|Occasions')
        ->assertScript(FlowScripts::SERVICE_NAMES, 'Haircut|Beard trim|Colour|Bridal trial')
        ->assertDontSee(CATEGORY_WALK_OFFLINE)
        ->click('.kr-booking__chip:nth-child(4)')
        ->assertScript("document.activeElement.classList.contains('kr-booking__group-heading') && document.activeElement.textContent", 'Occasions')
        ->click('Haircut')
        ->assertSeeIn('.kr-booking__estimate-name', 'Haircut')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'staff')
        ->click(Flow::said('staff.any'))
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::STEP, 'when');

    Flow::weekOf($page, $monday)
        ->click(sprintf('.kr-booking__day[value="%s"]', $monday->format('Y-m-d')))
        ->assertScript(FlowScripts::SETTLED, true)
        ->click(Flow::said('when.period.afternoon'))
        ->click(sprintf('.kr-booking__slot[value="%s"]', $start))
        ->assertScript(FlowScripts::STEP, 'details')
        ->type('customer_name', 'Casey Customer')
        ->type('customer_email', 'casey@example.com')
        ->click(Flow::said('estimate.continue'))
        ->assertScript(FlowScripts::stepReached('review'), true)
        ->assertSeeIn('.kr-booking__summary-service', 'Haircut')
        ->click(Flow::said('review.confirm'))
        ->assertScript(FlowScripts::stepReached('done'), true)
        ->assertNoJavaScriptErrors();

    expect(DB::table('tenant_luna.bookings')->pluck('status')->all())->toBe(['confirmed'])
        ->and(DB::table('tenant_luna.booking_items')->value('service_id'))->toBe(LunaFixture::id('services', 'Haircut'));
});

it('offers the service marked not online in the admin\'s wizard while the hosted page leaves it out', function () {
    $this->visit(hostedPage('luna'))
        ->assertScript(FlowScripts::SETTLED, true)
        ->assertDontSee(CATEGORY_WALK_OFFLINE);

    $page = Admin::signedIn($this, 'luna', Admin::owner('luna'));
    $page->click('.admin-new-booking')
        ->waitForText(adminLine('admin/customers.wizard.add_segment'))
        ->assertScript(sprintf("Array.from(document.querySelectorAll('#wizard-service-0 option')).some((option) => option.textContent === '%s')", CATEGORY_WALK_OFFLINE), true)
        ->assertNoJavaScriptErrors();
});
