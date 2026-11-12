<?php

use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

/*
 * The central host's 404: a path the host does not know answers 404 with a page in the site's
 * layout, Greek under /el and English elsewhere, noindex and with neither a canonical nor
 * alternates. It is a fallback on the central host's domain group, so the tenant and console
 * hosts keep their own, and it leaves the error envelope's paths to the envelope, where a caller
 * reads a machine answer and a verb a route does not take stays a 405.
 */

beforeEach(fn () => $this->withoutVite());

it('answers a path the central host does not know with the site\'s 404 in English, noindex, without alternates, a canonical or a cookie, {path}', function (string $path) {
    $response = $this->get(centralUrl($path));

    $response->assertNotFound();
    $document = siteDocument($response);

    expect($document->documentElement?->getAttribute('lang'))->toBe('en')
        ->and(trim((string) $document->querySelector('h1')?->textContent))->toBe(__('site.not_found.heading', [], 'en'))
        ->and($document->title)->toBe(__('site.not_found.title', ['name' => siteName()], 'en'))
        ->and($document->querySelector('meta[name="robots"]')?->getAttribute('content'))->toBe('noindex')
        ->and($document->querySelectorAll('link[rel="alternate"]'))->toHaveCount(0)
        ->and($document->querySelector('link[rel="canonical"]'))->toBeNull()
        ->and($response->headers->getCookies())->toBe([]);
})->with(['/nowhere', '/pricing/at/all', '/elsewhere', '/for/el', '/login', '/signup/luna/pending/more']);

it('answers a path under /el the central host does not know with the site\'s 404 in Greek, {path}', function (string $path) {
    $response = $this->get(centralUrl($path));

    $response->assertNotFound();
    $document = siteDocument($response);

    expect($document->documentElement?->getAttribute('lang'))->toBe('el')
        ->and(trim((string) $document->querySelector('h1')?->textContent))->toBe(__('site.not_found.heading', [], 'el'))
        ->and($document->querySelector('meta[name="robots"]')?->getAttribute('content'))->toBe('noindex');
})->with(['/el/nowhere', '/el/pricing/at/all']);

it('links the home in the 404\'s language, and switches to the other language\'s home', function (string $path, string $home, string $other) {
    $urls = app(TenantUrls::class);
    $document = siteDocument($this->get($urls->central($path)));

    expect($document->querySelector('main a')?->getAttribute('href'))->toBe($urls->central($home))
        ->and($document->querySelector('header .site-switch')?->getAttribute('href'))->toBe($urls->central($other));
})->with([
    'English' => ['/nowhere', '/', '/el'],
    'Greek' => ['/el/nowhere', '/el', '/'],
]);

it('leaves the error envelope\'s paths to the envelope on the central host, a 404 and a 405 alike', function () {
    $this->get(centralUrl('/api/v1/services'))
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
    $this->get(centralUrl('/admin/api/availability'))
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
    $this->get(centralUrl('/webhooks/billing'))
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');
});

it('keeps the tenant host\'s 404 and the console host\'s unknown path as they were, the site\'s 404 on neither', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    $urls = app(TenantUrls::class);
    $siteHeading = e(__('site.not_found.heading', [], 'en'));

    $tenant = $this->get(tenantUrl('acme', '/nowhere'));
    $tenant->assertNotFound();

    $console = $this->get($urls->console('/nowhere'));
    $console->assertRedirect($urls->console('/login'));

    expect((string) $tenant->getContent())->not->toContain($siteHeading)
        ->and((string) $this->get(tenantUrl('acme', '/el/nowhere'))->getContent())->not->toContain($siteHeading);
});

it('answers GET alone: another verb on a path no route names is the framework\'s 405', function () {
    $this->post(centralUrl('/nowhere'))->assertStatus(405);
    $this->post(centralUrl('/webhooks/nowhere/at/all'))->assertNotFound()->assertJsonPath('code', 'not_found');
});
