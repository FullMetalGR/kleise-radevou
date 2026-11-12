<?php

use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\SetSiteLocale;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Site\SiteRoutes;
use App\Tenancy\TenantUrls;
use Dom\HTMLDocument;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * The site's shell: one helper registering a page as two routes, the English one named for the
 * page and the Greek one under /el named el., each declaring public and carrying the locale
 * middleware with its language, and running without the session; the layout around the home, its
 * head read from the page's language file, its language switch linking the same page in the
 * other language and labelled in that language, and its nav and footer listing the site's pages
 * as the router holds them.
 *
 * A Greek sentence is read through the translator with the locale named, never spelled here.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * Each anchor's address and text under the element the selector names, in document order.
 *
 * @return list<array{string, string}>
 */
function siteLinksIn(HTMLDocument $document, string $selector): array
{
    $links = [];

    foreach ($document->querySelectorAll($selector.' a') as $anchor) {
        $links[] = [(string) $anchor->getAttribute('href'), trim((string) $anchor->textContent)];
    }

    return $links;
}

/**
 * The links the nav or the footer carries to pages in a language, in the order given: each
 * page's address and its label from site.php, a page served once per key under each of its keys
 * in order, labelled under its key.
 *
 * @param  array<string>  $pages
 * @return list<array{string, string}>
 */
function siteLinksTo(array $pages, SiteLanguage $language): array
{
    $registry = app(SitePages::class);
    $links = [];

    foreach ($pages as $page) {
        foreach ($registry->instances($page) as $parameters) {
            $label = __(implode('.', ['site.pages', Str::after($page, 'site.'), ...array_values($parameters)]), [], $language->value);

            $links[] = [$registry->url($page, $language, $parameters), is_string($label) ? $label : ''];
        }
    }

    return $links;
}

it('registers the home in both languages through the one helper: site.home at / and el.site.home at /el, each public and setting its locale', function () {
    $english = Route::getRoutes()->getByName('site.home');
    $greek = Route::getRoutes()->getByName('el.site.home');

    assert($english instanceof RegisteredRoute && $greek instanceof RegisteredRoute);

    expect($english->uri())->toBe('/')
        ->and($greek->uri())->toBe('el')
        ->and($english->methods())->toBe(['GET', 'HEAD'])
        ->and($greek->methods())->toBe(['GET', 'HEAD'])
        ->and($english->getDomain())->toBe(app(TenantUrls::class)->centralHost())
        ->and($greek->getDomain())->toBe(app(TenantUrls::class)->centralHost())
        ->and($english->gatherMiddleware())->toContain(PublicRoute::NAME, SetSiteLocale::NAME.':en')
        ->and($greek->gatherMiddleware())->toContain(PublicRoute::NAME, SetSiteLocale::NAME.':el');
});

it('runs a site page without the session: no session middleware runs, so no session row is written and no cookie is set', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    assert($route instanceof RegisteredRoute);

    $resolved = app(Router::class)->gatherRouteMiddleware($route);

    expect(array_filter($resolved, fn (mixed $middleware): bool => is_string($middleware) && is_a($middleware, StartSession::class, true)))->toBe([])
        ->and($resolved)->not->toContain(SiteRoutes::SESSION[1], SiteRoutes::SESSION[2]);

    $response = $this->get(app(TenantUrls::class)->central(SiteLanguage::ofRouteName($name)->uri('/')));

    $response->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
})->with(['site.home', 'el.site.home']);

it('heads each language\'s home with its file\'s title and meta description, its own address as canonical and the three alternates, each absolute on the central host', function (string $path, SiteLanguage $language) {
    $urls = app(TenantUrls::class);
    $document = siteDocument($this->get($urls->central($path)));
    $alternates = [];

    foreach ($document->querySelectorAll('link[rel="alternate"]') as $link) {
        $alternates[(string) $link->getAttribute('hreflang')] = $link->getAttribute('href');
    }

    expect($document->title)->toBe(__('home.title', ['name' => siteName()], $language->value))
        ->and($document->querySelector('meta[name="description"]')?->getAttribute('content'))->toBe(__('home.description', ['name' => siteName(), ...siteTrialDays()], $language->value))
        ->and($document->querySelector('link[rel="canonical"]')?->getAttribute('href'))->toBe($urls->central($path))
        ->and($alternates)->toBe(['en' => $urls->central('/'), 'el' => $urls->central('/el'), 'x-default' => $urls->central('/')])
        ->and($document->querySelector('meta[name="robots"]'))->toBeNull();
})->with([
    'English' => ['/', SiteLanguage::English],
    'Greek' => ['/el', SiteLanguage::Greek],
]);

it('carries the language switch to the same page in the other language, labelled in that language', function (string $path, string $other, SiteLanguage $language) {
    $urls = app(TenantUrls::class);
    $switch = siteDocument($this->get($urls->central($path)))->querySelector('header .site-switch');

    expect($switch?->getAttribute('href'))->toBe($urls->central($other))
        ->and($switch?->getAttribute('hreflang'))->toBe($language->value)
        ->and($switch?->getAttribute('lang'))->toBe($language->value)
        ->and(trim((string) $switch?->textContent))->toBe(__('site.language', [], $language->value));
})->with([
    'from English to Greek' => ['/', '/el', SiteLanguage::Greek],
    'from Greek to English' => ['/el', '/', SiteLanguage::English],
]);

it('labels the switch to Greek with the language\'s own name, which is no English word', function () {
    expect(__('site.language', [], 'el'))->not->toBe(__('site.language', [], 'en'))
        ->and(preg_match('/[^\x00-\x7F]/', __('site.language', [], 'el')))->toBe(1);
});

it('links the public name to the home in the page\'s language', function (string $path, string $home) {
    $urls = app(TenantUrls::class);
    $name = siteDocument($this->get($urls->central($path)))->querySelector('header .site-name');

    expect($name?->getAttribute('href'))->toBe($urls->central($home))
        ->and(trim((string) $name?->textContent))->toBe(siteName());
})->with([
    'English' => ['/', '/'],
    'Greek' => ['/el', '/el'],
]);

it('lists in the nav and the footer only the site\'s pages the router holds, so a page registered later is linked with no layout edit', function () {
    $urls = app(TenantUrls::class);
    $pages = app(SitePages::class);
    $linksOn = fn (SiteLanguage $language, string $selector): array => siteLinksIn(siteDocument($this->get($urls->central($language->uri('/')))), $selector);
    $held = $pages->names();

    // The nav: the pages SitePages::NAV names that the router holds, in its order; the footer:
    // every page but the home, in the order they were registered.
    foreach (SiteLanguage::cases() as $language) {
        expect($linksOn($language, 'header .site-nav'))->toBe(siteLinksTo(array_intersect(SitePages::NAV, $held), $language), $language->value)
            ->and($linksOn($language, 'footer .site-footer-nav'))->toBe(siteLinksTo(array_diff($held, [SitePages::HOME]), $language), $language->value);
    }

    // Every nav page no route holds yet, then a page no nav names, registered now. No language
    // holds the later page's label, so its link is labelled by the key.
    $later = [...array_diff(SitePages::NAV, $held), 'site.later'];
    $this->allowMissingKey('site.pages.later');

    foreach ($later as $page) {
        SiteRoutes::page('/'.Str::after($page, 'site.'), fn (): string => 'later', $page, 'home');
    }

    Route::getRoutes()->refreshNameLookups();

    foreach (SiteLanguage::cases() as $language) {
        expect($linksOn($language, 'header .site-nav'))->toBe(siteLinksTo(SitePages::NAV, $language), $language->value)
            ->and($linksOn($language, 'footer .site-footer-nav'))->toBe(siteLinksTo([...array_diff($held, [SitePages::HOME]), ...$later], $language), $language->value);
    }
});

it('puts the locale back once the page is answered, so the language is the request\'s alone', function () {
    $this->get(app(TenantUrls::class)->central('/el'))->assertOk();

    expect(app()->getLocale())->toBe(config('app.locale'));
});
