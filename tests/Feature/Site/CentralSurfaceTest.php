<?php

use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresBillingSignature;
use App\Http\Middleware\RequiresDecisionLink;
use App\Http\Middleware\RequiresManageToken;
use App\Http\Middleware\RequiresOperator;
use App\Http\Middleware\RequiresPayLink;
use App\Http\Middleware\RequiresPaymentSignature;
use App\Http\Middleware\RequiresPermission;
use App\Http\Middleware\RequiresPlatformSignature;
use App\Http\Middleware\RequiresSignIn;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Site\Vertical;
use App\Tenancy\TenantUrls;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
 * The central host's surface as it is declared and as it is served, the central host's own
 * ApiSurfaceTest.
 *
 * Every central route but the health check declares exactly one access on the route and never on
 * a group: public for the site, the signup and the 404, a provider's signature for each webhook.
 * The health check carries none, by design. The walk counts every declaration any host uses, so a
 * tenant's or the console's carried here by mistake is counted too.
 *
 * Every page of the site, as the registry reads them from the router, and a page served once per
 * key under each of its keys, the six verticals, answers in both languages at 200 with its <html
 * lang>, its three alternates and its canonical, its title, its meta description and its heading
 * from its language file in the HTML as served, read with no script run, and no cookie where the
 * page runs without the session, as every page but the contact page does, whose form keeps it. A
 * Greek browser asking for / reads the English page: nothing reads Accept-Language and nothing
 * redirects by it.
 */

beforeEach(fn () => $this->withoutVite());

/**
 * Every route registered on the central host, in registration order.
 *
 * @return list<RegisteredRoute>
 */
function centralRoutes(): array
{
    $host = app(TenantUrls::class)->centralHost();

    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RegisteredRoute $route): bool => $route->getDomain() === $host));
}

/**
 * The access declarations a route carries, of every kind any host declares.
 *
 * @return list<string>
 */
function centralAccessOf(RegisteredRoute $route): array
{
    $declarations = [
        PublicRoute::NAME, RequiresManageToken::NAME, RequiresDecisionLink::NAME, RequiresPayLink::NAME, RequiresSignIn::NAME,
        RequiresOperator::NAME, RequiresBillingSignature::NAME, RequiresPaymentSignature::NAME, RequiresPlatformSignature::NAME,
    ];

    return array_values(array_filter(
        array_filter($route->gatherMiddleware(), is_string(...)),
        fn (string $name): bool => in_array($name, $declarations, true) || str_starts_with($name, RequiresPermission::NAME.':'),
    ));
}

it('declares an access on every central route but the health check, exactly one and never two, and none on the health check', function () {
    $declared = [];

    foreach (centralRoutes() as $route) {
        $declared[$route->getName() ?? $route->uri()] = centralAccessOf($route);
    }

    expect($declared)->toHaveKey('health')
        ->and($declared['health'])->toBe([])
        ->and(array_keys(array_filter($declared, fn (array $markers, string $name): bool => $name !== 'health' && count($markers) !== 1, ARRAY_FILTER_USE_BOTH)))->toBe([]);
});

it('walks every page of the site in both languages, a page served once per key under each: 200, its lang, its three alternates, its canonical, its title, description and heading from its file as served, and no cookie but the contact page\'s', function () {
    $pages = app(SitePages::class);
    $walked = [];
    $sessioned = [];

    foreach ($pages->names() as $page) {
        $route = Route::getRoutes()->getByName($page);
        $sessionless = $route instanceof RegisteredRoute && in_array(StartSession::class, $route->excludedMiddleware(), true);

        if (! $sessionless) {
            $sessioned[] = $page;
        }

        foreach ($pages->instances($page) as $parameters) {
            foreach (SiteLanguage::cases() as $language) {
                $url = $pages->url($page, $language, $parameters);
                $response = $this->get($url);

                $response->assertOk();
                $document = siteDocument($response);
                $alternates = [];

                foreach ($document->querySelectorAll('link[rel="alternate"]') as $link) {
                    $alternates[(string) $link->getAttribute('hreflang')] = $link->getAttribute('href');
                }

                $copy = $pages->copyOf($page, $parameters);
                $filled = ['name' => siteName(), ...siteTrialDays()];
                $heading = __($copy.'.heading', $filled, $language->value);

                expect($document->documentElement?->getAttribute('lang'))->toBe($language->value, $url)
                    ->and($alternates)->toBe($pages->alternates($page, $parameters), $url)
                    ->and($document->querySelector('link[rel="canonical"]')?->getAttribute('href'))->toBe($url, $url)
                    ->and($document->querySelector('meta[name="robots"]'))->toBeNull()
                    ->and($document->title)->toBe(__($copy.'.title', $filled, $language->value), $url)->not->toBe($copy.'.title')
                    ->and($document->querySelector('meta[name="description"]')?->getAttribute('content'))->toBe(__($copy.'.description', $filled, $language->value), $url)->not->toBe($copy.'.description')
                    ->and($heading)->toBeString()->not->toBe($copy.'.heading')
                    ->and(trim((string) $document->querySelector('h1')?->textContent))->toBe($heading, $url);

                if ($sessionless) {
                    expect($response->headers->getCookies())->toBe([], $url)
                        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
                }

                $walked[] = $url;
            }
        }
    }

    // The six verticals, each in both languages: twelve pages, each its own copy.
    $verticals = [];

    foreach (Vertical::cases() as $vertical) {
        $verticals[] = centralUrl('/for/'.$vertical->value);
        $verticals[] = centralUrl('/el/for/'.$vertical->value);
    }

    // The one page that keeps the session is the contact page, its form's.
    expect($sessioned)->toBe(['site.contact'])
        ->and($walked)->toContain(centralUrl('/'), centralUrl('/el'), centralUrl('/contact'), centralUrl('/el/contact'))
        ->and(array_values(array_filter($walked, fn (string $url): bool => str_contains($url, '/for/'))))->toBe($verticals)
        ->and($walked)->toBe(array_values(array_unique($walked)));
});

it('answers / asked with a Greek Accept-Language with the English page at 200 and no redirect', function () {
    $response = $this->get(centralUrl('/'), ['Accept-Language' => 'el-GR,el;q=0.9']);

    $response->assertOk()->assertHeaderMissing('Location');

    expect(siteDocument($response)->documentElement?->getAttribute('lang'))->toBe('en');
});
