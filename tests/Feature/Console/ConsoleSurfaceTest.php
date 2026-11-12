<?php

use App\Http\Middleware\PublicRoute;
use App\Http\Middleware\RequiresActiveSubscription;
use App\Http\Middleware\RequiresOperator;
use App\Http\Middleware\ResolveTenant;
use App\Platform\PlatformAuditAction;
use App\Tenancy\TenantUrls;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;

/*
 * The console host's surface as it is declared, the console's own ApiSurfaceTest. Every route on
 * the console's domain declares its one access on the route and never on a group: public on the
 * door, operator on every screen and action behind it. The walk fails on a route declaring none
 * and on a route declaring two; the pin holds the surface verb by path in registration order, so
 * a route added, moved or re-declared fails here until this list is amended with it. The
 * write-route walk holds every console route taking a write verb to the platform audit action it
 * records, so a route cannot land unaudited.
 */

/**
 * Every route registered on the console's domain, in registration order.
 *
 * @return list<RegisteredRoute>
 */
function consoleRoutes(): array
{
    $domain = app(TenantUrls::class)->consoleHost();

    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RegisteredRoute $route): bool => $route->getDomain() === $domain));
}

/**
 * The access declarations a console route carries: public or operator, and nothing else counted.
 *
 * @return list<string>
 */
function consoleAccessOf(RegisteredRoute $route): array
{
    return array_values(array_filter(
        array_filter($route->gatherMiddleware(), is_string(...)),
        fn (string $name): bool => in_array($name, [PublicRoute::NAME, RequiresOperator::NAME], true),
    ));
}

/**
 * Every console write route by name, and the platform audit action it records. A new write route
 * adds its row here, and beside it a feature case that performs the action and reads its row; an
 * action outside the vocabulary is added to the enum and its CHECK first.
 *
 * @return array<string, PlatformAuditAction>
 */
function consoleAuditedWrites(): array
{
    return [
        'console.login.attempt' => PlatformAuditAction::SignIn,
        'console.logout' => PlatformAuditAction::SignOut,
        'console.tenants.store' => PlatformAuditAction::TenantCreated,
        'console.tenants.suspend' => PlatformAuditAction::TenantSuspended,
        'console.tenants.resume' => PlatformAuditAction::TenantResumed,
        'console.tenants.close' => PlatformAuditAction::TenantClosed,
        'console.tenants.reopen' => PlatformAuditAction::TenantReopened,
        'console.tenants.features' => PlatformAuditAction::FeaturesChanged,
        'console.tenants.users.password-link' => PlatformAuditAction::PasswordLinkSent,
        'console.tenants.support' => PlatformAuditAction::SupportOpened,
        'console.support.end' => PlatformAuditAction::SupportEnded,
        'console.messages.archive' => PlatformAuditAction::ContactArchived,
    ];
}

/**
 * What the walk finds wrong among the routes given: a route taking a write verb that the table
 * does not map, by its name or its URI where it has none, and a name the table maps that is no
 * route's taking a write verb, so a renamed or removed route leaves no stale row behind.
 *
 * @param  list<RegisteredRoute>  $routes
 * @return list<string>
 */
function unauditedConsoleWrites(array $routes): array
{
    $writes = [];

    foreach ($routes as $route) {
        if (array_intersect(array_filter($route->methods(), is_string(...)), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $writes[] = $route->getName() ?? $route->uri();
        }
    }

    return [
        ...array_map(fn (string $write): string => 'unmapped: '.$write, array_values(array_diff($writes, array_keys(consoleAuditedWrites())))),
        ...array_map(fn (string $name): string => 'no such write route: '.$name, array_values(array_diff(array_keys(consoleAuditedWrites()), $writes))),
    ];
}

it('declares an access on every route of the console host, exactly one and never two', function () {
    $routes = consoleRoutes();
    $declared = [];

    foreach ($routes as $route) {
        $declared[$route->getName() ?? $route->uri()] = consoleAccessOf($route);
    }

    expect($routes)->not->toBeEmpty()
        ->and(array_keys(array_filter($declared, fn (array $markers): bool => count($markers) !== 1)))->toBe([]);
});

it('spells the console surface verb by path in registration order: the door\'s three public, every other route operator', function () {
    $surface = [];

    foreach (consoleRoutes() as $route) {
        foreach (array_diff(array_values(array_filter($route->methods(), is_string(...))), ['HEAD']) as $method) {
            $surface[$method.' /'.ltrim($route->uri(), '/')] = implode(' and ', consoleAccessOf($route));
        }
    }

    expect($surface)->toBe([
        'GET /login' => PublicRoute::NAME,
        'POST /login' => PublicRoute::NAME,
        'POST /logout' => PublicRoute::NAME,
        'GET /' => RequiresOperator::NAME,
        'GET /tenants/new' => RequiresOperator::NAME,
        'POST /tenants' => RequiresOperator::NAME,
        'GET /tenants/closed/{id}' => RequiresOperator::NAME,
        'GET /tenants/{slug}' => RequiresOperator::NAME,
        'POST /tenants/{slug}/suspend' => RequiresOperator::NAME,
        'POST /tenants/{slug}/resume' => RequiresOperator::NAME,
        'POST /tenants/{slug}/close' => RequiresOperator::NAME,
        'POST /tenants/{slug}/reopen' => RequiresOperator::NAME,
        'POST /tenants/{slug}/features' => RequiresOperator::NAME,
        'POST /tenants/{slug}/users/{id}/password-link' => RequiresOperator::NAME,
        'POST /tenants/{slug}/support' => RequiresOperator::NAME,
        'POST /support/{id}/end' => RequiresOperator::NAME,
        'GET /messages' => RequiresOperator::NAME,
        'POST /messages/{id}/archive' => RequiresOperator::NAME,
        'GET /audit' => RequiresOperator::NAME,
        'GET /{path}' => RequiresOperator::NAME,
    ]);
});

it('names every console route, so the walk reads each by its name', function () {
    expect(array_map(fn (RegisteredRoute $route): ?string => $route->getName(), consoleRoutes()))->toBe([
        'console.login',
        'console.login.attempt',
        'console.logout',
        'console.tenants',
        'console.tenants.new',
        'console.tenants.store',
        'console.tenants.closed',
        'console.tenants.show',
        'console.tenants.suspend',
        'console.tenants.resume',
        'console.tenants.close',
        'console.tenants.reopen',
        'console.tenants.features',
        'console.tenants.users.password-link',
        'console.tenants.support',
        'console.support.end',
        'console.messages',
        'console.messages.archive',
        'console.audit',
        'console.not-found',
    ]);
});

it('holds every console write route to the platform audit action it records', function () {
    expect(consoleRoutes())->not->toBeEmpty()
        ->and(unauditedConsoleWrites(consoleRoutes()))->toBe([]);
});

it('fails the walk on a console write route the table lacks, by any write verb, and on a mapped name no write route holds', function () {
    $domain = app(TenantUrls::class)->consoleHost();
    Route::domain($domain)->post('/probe', fn () => '')->name('console.probe');
    Route::domain($domain)->put('/probe/{id}', fn () => '');
    Route::domain($domain)->delete('/probe/{id}', fn () => '')->name('console.probe.destroy');
    Route::domain($domain)->get('/probe', fn () => '')->name('console.probe.show');

    $walked = array_values(array_filter(consoleRoutes(), fn (RegisteredRoute $route): bool => $route->getName() !== 'console.logout'));

    expect(unauditedConsoleWrites($walked))->toBe([
        'unmapped: console.probe',
        'unmapped: probe/{id}',
        'unmapped: console.probe.destroy',
        'no such write route: console.logout',
    ]);
});

it('resolves no tenant and gates nothing by the subscription on any console route', function () {
    foreach (consoleRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        expect(in_array(ResolveTenant::class, $middleware, true))->toBeFalse($route->uri())
            ->and(in_array(RequiresActiveSubscription::NAME, $middleware, true))->toBeFalse($route->uri());
    }
});

it('puts the door\'s POST alone under the console\'s own sign-in limit, and limits no other console route', function () {
    $limits = [];

    foreach (consoleRoutes() as $route) {
        $limits[(string) $route->getName()] = array_values(array_filter(
            array_filter($route->gatherMiddleware(), is_string(...)),
            fn (string $name): bool => str_starts_with($name, 'throttle:'),
        ));
    }

    expect(array_filter($limits))->toBe(['console.login.attempt' => ['throttle:console-login']]);
});

it('takes any path under the catch-all, slashes included, and the GET alone, every other verb left to the tenant group', function () {
    $catchAll = Route::getRoutes()->getByName('console.not-found');

    assert($catchAll instanceof RegisteredRoute);

    expect($catchAll->wheres)->toBe(['path' => '.*'])
        ->and(array_values(array_filter($catchAll->methods(), is_string(...))))->toBe(['GET', 'HEAD']);
});
