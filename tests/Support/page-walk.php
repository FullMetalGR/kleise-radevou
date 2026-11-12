<?php

use App\Domain\Enum\Decision;
use App\Http\Middleware\ResolveTenant;
use App\Site\SiteLanguage;
use App\Tenancy\DecisionLinks;
use App\Tenancy\TenantPages;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
 * The suspended tenant's page walk: every route behind the tenant resolver, asked on a suspended
 * tenant's host, answers the one static page at 503. Kept outside tests/Pest.php with the imports
 * it alone needs, and loaded by tests/Pest.php, so every suite and every parallel worker holds
 * the three functions: the resolver's own case and the console's suspend share the walk.
 */

/**
 * Every route behind the tenant resolver, in registration order: the tenant host's surface.
 *
 * @return list<RegisteredRoute>
 */
function tenantRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RegisteredRoute $route): bool => in_array(ResolveTenant::class, $route->gatherMiddleware(), true),
    ));
}

/**
 * A value the constraint on that route parameter admits. Most of them constrain nothing, so a
 * digit does; the decision link's second segment is one of the two words a link can carry, the
 * admin's booking actions take a uuid alone, the set-password link's page takes 64 hex characters
 * alone, its malformed sibling the digit, and a customer's page and its erasure an address alone,
 * the page's GET otherwise falling to the 404 page's catch-all and the erasure's POST to its 405;
 * the top bar's language write one of the two languages alone, any other its 405; anything else
 * matches no route at all.
 */
function tenantRouteSample(string $parameter): string
{
    return match ($parameter) {
        DecisionLinks::DECISION => Decision::Approve->value,
        'uuid' => '00000000-0000-4000-8000-000000000000',
        'token' => str_repeat('a', 64),
        'email' => 'someone@example.com',
        'language' => SiteLanguage::English->value,
        default => '1',
    };
}

/**
 * The suspended tenant's walk: every route behind the resolver, asked on the host of the slug
 * given, answers the one static page at 503, the probe included. Shared by the resolver's own
 * case, on a row a factory wrote suspended, and the console's, on a row the console's suspend
 * wrote.
 *
 * A route parameter is given a value its constraint admits, since a URI holding the literal
 * placeholder matches no route and never reaches the middleware, and so does one holding a value
 * the constraint refuses. Each route is asked with a method it takes, since one it does not is a
 * 405 from the router.
 *
 * A call is keyed by its method as well as its path, because two methods on one URI are two
 * routes: the decision link's page and the POST that decides on it share a path, and a key that
 * was the path alone would walk one of them and count the other walked.
 */
function pausedPageWalk(TestCase $test, string $slug): void
{
    $calls = [];

    foreach (tenantRoutes() as $route) {
        $method = $route->methods()[0] ?? 'GET';
        $method = is_string($method) ? $method : 'GET';
        $uri = (string) preg_replace_callback('/\{([^}]+)\}/', fn (array $parameter): string => tenantRouteSample((string) $parameter[1]), $route->uri());
        $calls[$method.' '.$uri] = [$method, $uri];
    }

    $pages = [];

    foreach ($calls as $call => [$method, $uri]) {
        $response = $test->call($method, tenantUrl($slug, '/'.$uri));
        // The page's lang is the request's language: the prefix of a page twin's path, else the
        // tenant default, English for these tenants, and its sentences that language's.
        $prefix = TenantPages::prefixOf($uri) ?? SiteLanguage::English;
        $language = $prefix->value;

        $response->assertStatus(503)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee(pageSentence('pages.suspended.heading', [], $prefix))
            ->assertSee(pageSentence('pages.suspended.sentence', [], $prefix));

        expect((string) $response->getContent())->toContain('<html lang="'.$language.'">');

        $pages[$language][$call] = $response->getContent();
    }

    // One call for every route behind the resolver, which is what makes this a walk rather than
    // a sample: a route whose call collapsed into another's would be counted as walked without
    // ever having been asked. One page in each language, the one static page.
    expect($calls)->toHaveCount(count(tenantRoutes()))
        ->and(array_keys($calls))->toContain('GET probe', 'GET el/manage/00000000-0000-4000-8000-000000000000', 'POST en/my-bookings/link')
        ->and(array_keys($pages))->toEqualCanonicalizing(['en', 'el'])
        ->and(array_unique($pages['en'] ?? []))->toHaveCount(1)
        ->and(array_unique($pages['el'] ?? []))->toHaveCount(1);
}
