<?php

use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Support\Facades\DB;
use Tests\Browser\Support\Console;

/*
 * The tenant list in a real browser: an operator signs in through the console's door and lands on
 * the list, the console's home, where the two demo tenants the seeder provisioned through
 * tenant:provision list as Free, the console's zone named once in the top bar; a search narrows
 * the list to one, Clear lifts it, and the status narrows it again, to none for suspended and to
 * both for active, each answer the server's and each filter in the address bar.
 *
 * From the list an operator opens luna's detail: its name, its plan Free and its state active, the
 * counts the demo catalog and hours seeders leave, read from the seeded schema rather than spelled
 * here, the services the active ones alone as the staff are (the seed holds an inactive service),
 * and the two links out to its origin.
 *
 * Luna is provisioned before pier, so pier, the later signup, lists first.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
});

afterEach(fn () => dropDemoSchemas());

it('signs in, sees the two demo tenants as Free, and narrows the list by search and by status', function () {
    addOperator();

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2')
        ->assertPathIs('/')
        ->assertTitle('Tenants - '.siteName())
        ->assertAttribute('.admin-rail a[href="/"]', 'aria-current', 'page')
        ->assertSeeIn('.admin-topbar .admin-console-zone', 'Times are in Europe/Athens')
        ->assertSeeIn('.admin-tenants__row:nth-child(1) .admin-tenants__slug', 'pier')
        ->assertSeeIn('.admin-tenants__row:nth-child(1) .admin-tenants__name', 'Pier Physiotherapy')
        ->assertSeeIn('.admin-tenants__row:nth-child(1) .admin-tenants__plan', 'Free')
        ->assertSeeIn('.admin-tenants__row:nth-child(1) .admin-tenants__status', 'Active')
        ->assertSeeIn('.admin-tenants__row:nth-child(2) .admin-tenants__slug', 'luna')
        ->assertSeeIn('.admin-tenants__row:nth-child(2) .admin-tenants__name', 'Luna Hair Studio')
        ->assertSeeIn('.admin-tenants__row:nth-child(2) .admin-tenants__plan', 'Free')
        ->assertNoJavaScriptErrors();

    $page->type('#tenants-search', 'pier')
        ->waitForText('1 of 1')
        ->assertQueryStringHas('search', 'pier')
        ->assertSeeIn('.admin-tenants__slug', 'pier')
        ->assertDontSeeIn('.admin-tenants', 'Luna Hair Studio');

    $page->click('.admin-tenants-clear')
        ->waitForText('2 of 2')
        ->assertQueryStringMissing('search')
        ->assertCount('.admin-tenants__row', 2);

    $page->select('#tenants-status', 'suspended')
        ->waitForText('No tenants match these filters.')
        ->assertQueryStringHas('status', 'suspended');

    $page->select('#tenants-status', 'active')
        ->waitForText('2 of 2')
        ->assertQueryStringHas('status', 'active')
        ->assertSeeIn('.admin-tenants__row:nth-child(1) .admin-tenants__slug', 'pier')
        ->assertSeeIn('.admin-tenants__row:nth-child(2) .admin-tenants__slug', 'luna')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});

it('opens luna from the list and reads its name, plan, state, counts and the two links out', function () {
    addOperator();
    $services = DB::table('tenant_luna.services')->where('status', 'active')->count();
    $staff = DB::table('tenant_luna.staff')->where('status', 'active')->count();
    $confirmed = DB::table('tenant_luna.bookings')->where('status', 'confirmed')->count();
    $urls = app(TenantUrls::class);

    $page = Console::signIn($this, 'pat@inbox.example', 'operator password')
        ->waitForText('2 of 2');

    $page->click('.admin-tenants__row:nth-child(2) .admin-tenants__slug')
        ->waitForText('Plan and subscription')
        ->assertPathIs('/tenants/luna')
        ->assertTitle('Luna Hair Studio - '.siteName())
        ->assertAttribute('.admin-rail a[href="/"]', 'aria-current', 'page')
        ->assertSeeIn('.admin-topbar .admin-console-zone', 'Times are in Europe/Athens')
        ->assertSeeIn('.admin-tenant__name', 'Luna Hair Studio')
        ->assertSeeIn('.admin-tenant__slug', 'luna')
        ->assertSeeIn('.admin-tenant__plan', 'Free')
        ->assertSeeIn('.admin-tenant__status', 'Active')
        ->assertSeeIn('.admin-tenant__state', 'Active')
        ->assertSeeIn('.admin-tenant__timezone', 'Europe/Athens')
        ->assertSeeIn('.admin-tenant__no-subscription', 'No subscription with the billing provider.')
        ->assertSeeIn('.admin-tenant-people__email', 'owner@luna.example')
        ->assertScript("document.querySelector('.admin-tenant__services').textContent", (string) $services)
        ->assertScript("document.querySelector('.admin-tenant__staff').textContent", (string) $staff)
        ->assertScript("document.querySelector('.admin-tenant-bookings [data-status=\"confirmed\"]').textContent", (string) $confirmed)
        ->assertAttribute('.admin-tenant-links__hosted', 'href', $urls->hostedPage(Slug::of('luna')))
        ->assertAttribute('.admin-tenant-links__sign-in', 'href', $urls->signIn(Slug::of('luna')))
        ->assertAttribute('.admin-tenant-links__audit', 'href', '/audit?tenant=luna')
        ->assertNoJavaScriptErrors();

    expect($services)->toBeGreaterThan(0)
        ->and($staff)->toBeGreaterThan(1);
});
