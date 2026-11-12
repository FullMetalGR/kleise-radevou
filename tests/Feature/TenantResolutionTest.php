<?php

use App\Http\Middleware\ResolveTenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use App\Tenancy\TenantUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

pest()->use(RefreshDatabase::class);

/*
 * Tenant resolution on the wire, through the real router and the real middleware. The probe is
 * the one tenant route, and it enters the transaction runner, which refuses the transaction this
 * suite wraps each test in, so an active tenant reaching the probe is tested in the Concurrency
 * suite instead. What is tested here never reaches the runner: every refusal, the suspended
 * page, and the binding, the last through routes registered beside the probe for the test.
 */

/**
 * Registers a route like the probe, on the same host pattern behind the same middleware, whose
 * body is the test's.
 */
function besideTheProbe(string $uri, Closure $action): void
{
    $probe = Route::getRoutes()->getByName('probe');

    assert($probe instanceof RegisteredRoute);

    Route::domain((string) $probe->getDomain())->middleware($probe->middleware())->get($uri, $action);
}

/**
 * Everything about a response a caller can compare, less the two headers that differ between
 * any two requests: the session cookie and the date.
 *
 * @param  TestResponse<Response>  $response
 * @return array{int, array<string, mixed>, string|false}
 */
function fingerprint(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['set-cookie'], $headers['date']);

    return [$response->status(), $headers, $response->getContent()];
}

it('answers 404 for a reserved label, and never a redirect', function (string $label) {
    $this->get(tenantUrl($label, '/probe'))
        ->assertNotFound()
        ->assertHeaderMissing('Location');
})->with(['api', 'www', 'admin', 'localhost']);

it('serves the demo label, the one reserved label that resolves', function () {
    // No signup provisions a reserved label, so a tenant provisioned under another takes the
    // demo's label here and keeps the schema its row names.
    $tenant = provisionTenant('salon')->tenant;
    DB::table('tenants')->where('id', $tenant->id)->update(['slug' => 'demo']);

    besideTheProbe('/_resolved', fn (CurrentTenant $current): string => $current->get()->slug);

    $this->get(tenantUrl('demo', '/_resolved'))->assertOk()->assertSeeText('demo');
});

it('answers 404 for an unknown slug', function () {
    $this->get(tenantUrl('nobody', '/probe'))->assertNotFound();
});

it('answers 404 for a tenant still provisioning', function () {
    Tenant::factory()->create(['slug' => 'pending', 'status' => TenantStatus::Provisioning]);

    $this->get(tenantUrl('pending', '/probe'))->assertNotFound();
});

it('answers 404 for a label that could not be a slug, {label}', function (string $label) {
    $this->get(tenantUrl($label, '/probe'))->assertNotFound();
})->with(['a_b', 'xn--abc', '-acme']);

it('answers a reserved label, an unknown slug, a provisioning tenant and a malformed label with one 404, as {format}', function (bool $json) {
    Tenant::factory()->create(['slug' => 'pending', 'status' => TenantStatus::Provisioning]);

    $fingerprints = array_map(function (string $label) use ($json): array {
        $url = tenantUrl($label, '/probe');

        return fingerprint($json ? $this->getJson($url) : $this->get($url));
    }, ['api', 'nobody', 'pending', 'a_b']);

    expect($fingerprints[0][0])->toBe(404)
        ->and(array_unique(array_map(fn (array $fingerprint): string => serialize($fingerprint), $fingerprints)))->toHaveCount(1);
})->with(['html' => [false], 'json' => [true]]);

it('is not reached from the central host, from a host two labels deep, or from another domain', function (string $url) {
    // The central host's 404 is the site's page, which links the site's stylesheet.
    $this->withoutVite();

    $this->get($url)->assertNotFound();
})->with([
    'the central host' => [fn (): string => centralUrl('/probe')],
    'two labels deep' => [fn (): string => tenantUrl('a.b', '/probe')],
    'another domain' => ['http://acme.example.com/probe'],
]);

it('serves a suspended tenant the static page on every tenant route, the probe included', function () {
    provisionTenant('paused');
    DB::table('tenants')->where('slug', 'paused')->update(['status' => TenantStatus::Suspended->value]);

    pausedPageWalk($this, 'paused');
});

it('binds an active tenant for the request, loaded once, carrying entitlements, subscription state and timezone', function () {
    $tenant = provisionTenant('acme', ['timezone' => 'Europe/Athens'])->tenant;
    DB::table('tenants')->where('id', $tenant->id)->update(['entitlements' => '{staff}', 'subscription_state' => 'grace']);

    besideTheProbe('/_resolved', function (CurrentTenant $current): array {
        $tenant = $current->get();

        return [
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'entitlements' => $tenant->entitlements,
            'subscription_state' => $tenant->subscription_state,
            'timezone' => $tenant->timezone,
            'queries' => count(DB::getQueryLog()),
            'parameters' => request()->route()?->parameters(),
        ];
    });

    DB::enableQueryLog();

    $this->get(tenantUrl('acme', '/_resolved'))->assertOk()->assertExactJson([
        'slug' => 'acme',
        'status' => 'active',
        'entitlements' => ['staff'],
        'subscription_state' => 'grace',
        'timezone' => 'Europe/Athens',
        // Two reads: the tenant's row, loaded once, and its settings' language, which the request
        // of a tenant holding a schema reads for its default.
        'queries' => 2,
        'parameters' => [],
    ]);
});

it('resolves a closing tenant as it would an active one', function () {
    provisionTenant('leaving');
    DB::table('tenants')->where('slug', 'leaving')->update(['status' => TenantStatus::Closing->value]);

    besideTheProbe('/_resolved', fn (CurrentTenant $current): array => [
        'slug' => $current->get()->slug,
        'status' => $current->get()->status->value,
    ]);

    $this->get(tenantUrl('leaving', '/_resolved'))->assertOk()->assertExactJson(['slug' => 'leaving', 'status' => 'closing']);
});

it('releases the tenant once the request is answered, and when the route throws', function () {
    provisionTenant('acme');
    $current = app(CurrentTenant::class);

    besideTheProbe('/_resolved', fn (CurrentTenant $current): string => $current->get()->slug);
    besideTheProbe('/_throws', function (): never {
        throw new RuntimeException('the route failed');
    });

    $this->get(tenantUrl('acme', '/_resolved'))->assertOk();

    expect(fn () => $current->get())->toThrow(LogicException::class, 'No tenant is bound');

    $this->get(tenantUrl('acme', '/_throws'))->assertStatus(500);

    expect(fn () => $current->get())->toThrow(LogicException::class, 'No tenant is bound');
});

it('binds nothing on a central route, where a read is a programming error', function () {
    expect(fn () => app(CurrentTenant::class)->get())->toThrow(LogicException::class, 'No tenant is bound');
});

it('binds nothing on a console route either, where a read is the same programming error', function () {
    $door = Route::getRoutes()->getByName('console.login');

    assert($door instanceof RegisteredRoute);

    // A POST: the console's catch-all answers every GET on its host and was registered first, so
    // a GET probe added here would never be reached.
    Route::domain((string) $door->getDomain())->middleware($door->middleware())->post('/_resolved', function (CurrentTenant $current): string {
        try {
            return $current->get()->slug;
        } catch (LogicException $e) {
            return $e->getMessage();
        }
    });

    $this->post(app(TenantUrls::class)->console('/_resolved'))->assertOk()->assertSeeText('No tenant is bound');
});

it('puts the middleware on every tenant route and on no other', function () {
    $tenantDomain = Route::getRoutes()->getByName('probe')?->getDomain();
    $routes = Route::getRoutes()->getRoutes();

    expect($tenantDomain)->toBeString()->toStartWith('{')
        ->and($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $resolves = in_array(ResolveTenant::class, $route->gatherMiddleware(), true);

        expect($resolves)->toBe($route->getDomain() === $tenantDomain, $route->uri());
    }
});
