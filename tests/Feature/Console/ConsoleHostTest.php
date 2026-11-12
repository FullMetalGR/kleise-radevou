<?php

use App\Http\Middleware\HandleConsoleInertiaRequests;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

pest()->use(RefreshDatabase::class);

/*
 * The console's host: console. under the central host, its routes a domain group of their own
 * registered before the tenant group, resolving no tenant. The door renders the console/Login
 * page from the admin bundle through the console's own adapter and root view, and the door asked
 * on the central host or on a tenant's is not found.
 *
 * A tenant page asked on this host is the console's own unknown path: the catch-all GET answers
 * it, the door for a visitor with no operator session and the console's 404 for an operator
 * (ConsoleSignInTest). Another verb falls through to the tenant group where a tenant route takes
 * that verb on that path, which refuses the reserved label with a 404 as for every reserved
 * label, and is a 405 off the console's GET catch-all where none does.
 */

it('answers the door on the console host with the console/Login page from the admin bundle, through the console adapter and root view', function () {
    $this->get(app(TenantUrls::class)->console('/login'))
        ->assertOk()
        ->assertViewIs(HandleConsoleInertiaRequests::ROOT_VIEW)
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertSee('build/admin/', false)
        ->assertInertia(fn (Assert $page) => $page->component('console/Login')
            ->where('flash', null)
            ->where('operator', null)
            ->where('refusal', null));
});

it('shares the flash, the operator, an empty rail, the console\'s zone, the brand and the shell\'s strings in English and nothing a tenant page shares', function () {
    $page = $this->get(app(TenantUrls::class)->console('/login'))->assertOk()->viewData('page');

    assert(is_array($page) && is_array($page['props']));

    expect(array_keys($page['props']))->toEqualCanonicalizing(['errors', 'flash', 'operator', 'nav', 'zone', 'brand', 'refusal', 'strings'])
        ->and($page['props']['strings'])->toBe(['shell' => __('admin/shell', [], 'en')])
        ->and($page['props']['nav'])->toBe([])
        ->and($page['props']['zone'])->toBe('Europe/Athens')
        ->and($page['props']['brand'])->toBe(['name' => siteName()]);
});

it('hands the flash to the door as the toast reads it', function () {
    $this->withSession(['flash' => ['kind' => 'success', 'message' => 'You have signed out.']])
        ->get(app(TenantUrls::class)->console('/login'))
        ->assertInertia(fn (Assert $page) => $page->where('flash', ['kind' => 'success', 'message' => 'You have signed out.']));
});

it('versions the console\'s pages by the admin build\'s manifest, as the central host\'s are', function () {
    $manifest = public_path('build/admin/manifest.json');

    expect(app(HandleConsoleInertiaRequests::class)->version(request()))
        ->toBe(is_file($manifest) ? hash_file('xxh128', $manifest) : null);
});

it('reads a tenant page asked on the console host as the console\'s own unknown path: the door for a visitor with no operator session, {path}', function (string $path) {
    $this->get(app(TenantUrls::class)->console($path))->assertRedirect(app(TenantUrls::class)->console('/login'));
})->with(['/probe', '/admin', '/admin/login', '/embed.js', '/api/v1/config']);

it('leaves a tenant write asked on the console host to the tenant group, which refuses its label as reserved, 404, {method} {path}', function (string $method, string $path) {
    expect(config('tenancy.reserved_slugs'))->toContain('console');

    $this->call($method, app(TenantUrls::class)->console($path))->assertNotFound();
})->with([
    ['POST', '/api/v1/holds'],
    ['POST', '/admin/login'],
    ['PUT', '/admin/settings'],
    ['DELETE', '/admin/services/1'],
]);

it('answers 405 for another verb on a path no tenant route takes it on, off the console\'s GET catch-all, {method} {path}', function (string $method, string $path) {
    $this->call($method, app(TenantUrls::class)->console($path))->assertStatus(405);
})->with([
    ['POST', '/nothing-here'],
    ['DELETE', '/probe'],
]);

it('answers 404 for the console\'s door asked on the central host', function () {
    // The central host's 404 is the site's page, which links the site's stylesheet.
    $this->withoutVite();

    $this->get(centralUrl('/login'))->assertNotFound();
});

it('answers 404 for the console\'s door asked on a tenant host, the tenant active', function () {
    Tenant::factory()->create(['slug' => 'acme']);

    $this->get(tenantUrl('acme', '/login'))->assertNotFound();
});

it('reserves new, the label of the free tenant\'s form, so GET /tenants/new names no tenant\'s detail and no tenant host answers to it', function () {
    expect(config('tenancy.reserved_slugs'))->toContain('console', 'new');

    // A row holding the label, which nothing but a factory could now write, answers nothing.
    Tenant::factory()->create(['slug' => 'new']);

    $this->get(tenantUrl('new', '/'))->assertNotFound();
    $this->get(tenantUrl('new', '/admin/login'))->assertNotFound();
});
