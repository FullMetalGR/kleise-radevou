<?php

use App\Site\SitePages;
use App\Site\SiteRoutes;
use App\Site\Vertical;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
 * The sitemap: GET /sitemap.xml lists every page of the site in both languages, each <url> its
 * <loc> and the three xhtml:link alternates, built from the route registry, every GET
 * route named under site. and the signup form that has its Greek twin, so a page registered
 * later is listed with no edit, and a page served once per key, the six verticals, under each
 * of its keys. No lastmod; no session and no cookie.
 */

const SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

const SITEMAP_XHTML = 'http://www.w3.org/1999/xhtml';

/**
 * The sitemap's entries as served: each <loc> and its alternates by hreflang.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, array<string, string>>
 */
function sitemapEntries(TestResponse $response): array
{
    $document = new DOMDocument;
    $document->loadXML((string) $response->getContent());

    expect($document->documentElement?->namespaceURI)->toBe(SITEMAP_NAMESPACE)
        ->and($document->documentElement?->localName)->toBe('urlset');

    $entries = [];

    foreach ($document->getElementsByTagNameNS(SITEMAP_NAMESPACE, 'url') as $url) {
        $loc = trim((string) $url->getElementsByTagNameNS(SITEMAP_NAMESPACE, 'loc')->item(0)?->textContent);
        $entries[$loc] = [];

        foreach ($url->getElementsByTagNameNS(SITEMAP_XHTML, 'link') as $link) {
            expect($link->getAttribute('rel'))->toBe('alternate');

            $entries[$loc][$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
    }

    return $entries;
}

it('answers XML with no cookie, no session and no lastmod', function () {
    $response = $this->get(centralUrl('/sitemap.xml'));

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/xml')
        ->and($response->headers->getCookies())->toBe([])
        ->and((string) $response->getContent())->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->not->toContain('lastmod');
});

it('lists the two home addresses first, and every address with the three alternates, absolute on the central host, each of them listed', function () {
    $urls = app(TenantUrls::class);
    $entries = sitemapEntries($this->get(centralUrl('/sitemap.xml')));
    $alternates = ['en' => $urls->central('/'), 'el' => $urls->central('/el'), 'x-default' => $urls->central('/')];

    expect(array_slice($entries, 0, 2))->toBe([
        $urls->central('/') => $alternates,
        $urls->central('/el') => $alternates,
    ]);

    foreach ($entries as $loc => $links) {
        expect(array_keys($links))->toBe(['en', 'el', 'x-default'], $loc)
            ->and($links['x-default'])->toBe($links['en'], $loc)
            ->and(in_array($loc, [$links['en'], $links['el']], true))->toBeTrue($loc)
            ->and($entries)->toHaveKeys([$links['en'], $links['el']]);
    }
});

it('lists the signup form in both languages, now its Greek twin exists, with its three alternates, after the site\'s pages, and none of the signup\'s other routes', function () {
    $urls = app(TenantUrls::class);
    $entries = sitemapEntries($this->get(centralUrl('/sitemap.xml')));
    $alternates = ['en' => $urls->central('/signup'), 'el' => $urls->central('/el/signup'), 'x-default' => $urls->central('/signup')];
    $locs = array_keys($entries);

    expect(array_slice($locs, -2))->toBe([$urls->central('/signup'), $urls->central('/el/signup')])
        ->and($entries[$urls->central('/signup')])->toBe($alternates)
        ->and($entries[$urls->central('/el/signup')])->toBe($alternates)
        ->and(array_values(array_filter($locs, fn (string $loc): bool => str_contains($loc, '/signup/'))))->toBe([]);
});

it('lists the six verticals each as a page in both languages: twelve addresses, each with its three alternates, in Vertical\'s order', function () {
    $urls = app(TenantUrls::class);
    $entries = sitemapEntries($this->get(centralUrl('/sitemap.xml')));
    $expected = [];

    foreach (Vertical::cases() as $vertical) {
        $alternates = ['en' => $urls->central('/for/'.$vertical->value), 'el' => $urls->central('/el/for/'.$vertical->value), 'x-default' => $urls->central('/for/'.$vertical->value)];
        $expected[$urls->central('/for/'.$vertical->value)] = $alternates;
        $expected[$urls->central('/el/for/'.$vertical->value)] = $alternates;
    }

    expect(array_filter($entries, fn (string $loc): bool => str_contains($loc, '/for/'), ARRAY_FILTER_USE_KEY))->toBe($expected)
        ->and($expected)->toHaveCount(12);
});

it('lists a page served once per key, registered later, under each of its keys with no edit', function () {
    $urls = app(TenantUrls::class);

    SiteRoutes::page('/later/{kind}', fn (): string => 'later', 'site.later', 'home', ['one', 'two']);
    Route::getRoutes()->refreshNameLookups();

    $entries = sitemapEntries($this->get(centralUrl('/sitemap.xml')));

    expect(array_slice(array_keys($entries), -4))->toBe([
        $urls->central('/later/one'),
        $urls->central('/el/later/one'),
        $urls->central('/later/two'),
        $urls->central('/el/later/two'),
    ])
        ->and($entries[$urls->central('/el/later/two')])->toBe(['en' => $urls->central('/later/two'), 'el' => $urls->central('/el/later/two'), 'x-default' => $urls->central('/later/two')]);
});

it('refuses a page with a parameter and no keys, keys for a page with no parameter, and a page with two parameters, since the registry could list none of them', function () {
    $later = fn (): string => 'later';

    expect(fn () => SiteRoutes::page('/later/{kind}', $later, 'site.later', 'home'))->toThrow(LogicException::class)
        ->and(fn () => SiteRoutes::page('/later', $later, 'site.later', 'home', ['one']))->toThrow(LogicException::class)
        ->and(fn () => SiteRoutes::page('/later/{kind}/{size}', $later, 'site.later', 'home', ['one']))->toThrow(LogicException::class);
});

it('lists a page registered later with no edit', function () {
    $urls = app(TenantUrls::class);
    $before = array_keys(sitemapEntries($this->get(centralUrl('/sitemap.xml'))));

    SiteRoutes::page('/later', fn (): string => 'later', 'site.later', 'home');
    Route::getRoutes()->refreshNameLookups();

    expect($before)->not->toContain($urls->central('/later'))
        ->and(array_keys(sitemapEntries($this->get(centralUrl('/sitemap.xml')))))->toBe([
            ...$before,
            $urls->central('/later'),
            $urls->central('/el/later'),
        ]);
});

it('reads from the registry every route named under site. and the signup form that has its Greek twin, and nothing else', function () {
    $router = new Router(app(Dispatcher::class), app());
    Route::swap($router);

    SiteRoutes::page('/', fn (): string => 'home', 'site.home', 'home');
    SiteRoutes::twin('GET', '/signup', fn (): string => 'form', 'signup');
    SiteRoutes::twin('POST', '/signup', fn (): string => 'reserve', 'signup.store');
    SiteRoutes::twin('GET', '/signup/{slug}/pending', fn (): string => 'pending', 'signup.pending');
    SiteRoutes::twin('POST', '/contact', fn (): string => 'sent', 'site.contact.send');
    Route::get('/sitemap.xml', fn (): string => 'sitemap')->name('site.sitemap');
    SiteRoutes::fallback(fn (): string => 'not found');
    $router->getRoutes()->refreshNameLookups();

    expect((new SitePages($router, app(UrlGenerator::class), app(TenantUrls::class)))->indexed())->toBe(['site.home', 'signup']);
});
