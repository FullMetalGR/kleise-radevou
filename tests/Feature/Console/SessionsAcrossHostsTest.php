<?php

use App\Http\Middleware\PublicRoute;
use App\Models\User;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/*
 * The two refusals across hosts. A tenant user's session presented on the console host names
 * nobody, since the console guard reads the operators alone. An operator's session presented on a
 * tenant host names nobody either, since the admin reads the web guard by name and that guard
 * reads the users alone: every route under the admin prefix but the door answers it exactly as it
 * answers a guest, the sign-in page for a page or an action and the 401 envelope for the one JSON
 * endpoint asked as JSON. Each refusal has its control beside it, the guard's own key naming its
 * own person on its own host, and the admin is shown to read the web guard by name rather than
 * the default, which acting as an operator makes the console's. The session cookie is host-only
 * on every host besides, so neither is ever presented in practice; these hold the guards apart
 * were it ever to be.
 */

/**
 * A value the constraint on an admin route's parameter admits, so a walked call reaches the route
 * it names rather than the 404 page's catch-all, which would answer a guest the same way.
 */
function crossHostSample(string $parameter): string
{
    return match ($parameter) {
        'uuid' => '00000000-0000-4000-8000-000000000000',
        'email' => 'someone@example.com',
        'file' => 'photo.jpg',
        'kind' => 'service',
        'path' => 'nothing-here',
        'language' => 'en',
        default => '1',
    };
}

/**
 * Every call under the admin prefix on a tenant host but the door's, by verb and path, each with
 * the name of the route it must reach.
 *
 * @return array<string, array{method: string, path: string, name: string}>
 */
function crossHostAdminCalls(): array
{
    $domain = '{tenant}.'.app(TenantUrls::class)->centralHost();
    $calls = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getDomain() !== $domain || ! ($route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'))) {
            continue;
        }

        if (in_array(PublicRoute::NAME, $route->gatherMiddleware(), true)) {
            continue;
        }

        $path = '/'.preg_replace_callback('/\{([^}]+)\}/', fn (array $parameter): string => crossHostSample((string) $parameter[1]), $route->uri());

        foreach (array_diff(array_values(array_filter($route->methods(), is_string(...))), ['HEAD']) as $method) {
            $calls[$method.' /'.$route->uri()] = ['method' => $method, 'path' => $path, 'name' => (string) $route->getName()];
        }
    }

    return $calls;
}

/**
 * What a call is answered: the status, where it sends the browser, and the body where it is JSON.
 *
 * @param  TestResponse<Response>  $response
 * @return array{status: int, location: string|null, json: mixed}
 */
function crossHostAnswer(TestResponse $response): array
{
    $json = str_contains((string) $response->headers->get('Content-Type'), 'json') ? $response->json() : null;

    return ['status' => $response->status(), 'location' => $response->headers->get('Location'), 'json' => $json];
}

/**
 * The key a session guard keeps its signed-in id under, the one thing a session cookie presented
 * on the other host would carry for it.
 */
function crossHostSessionKey(string $guard): string
{
    $session = Auth::guard($guard);

    return $session instanceof SessionGuard ? $session->getName() : throw new LogicException("The {$guard} guard keeps no session.");
}

it('names nobody on the console host for a tenant user\'s web session: the home, an unknown path and the door read as for a guest', function () {
    $tenant = schemaBehind(Tenant::factory()->create(['slug' => 'acme']));
    $user = User::query()->create(['tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => 'owner password', 'role' => 'owner']);

    $this->actingAs($user, 'web');

    $this->get(consoleUrl('/'))->assertRedirect(consoleUrl('/login'));
    $this->get(consoleUrl('/nothing-here'))->assertRedirect(consoleUrl('/login'));
    $this->get(consoleUrl('/login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Login')->where('operator', null));
});

it('names nobody on the console host for a web session\'s own key in the session, the cookie were it ever presented here', function () {
    $tenant = schemaBehind(Tenant::factory()->create(['slug' => 'acme']));
    $operator = addOperator();
    // A user holding the operator's id, so a guard reading the other's key would find somebody.
    $user = User::query()->forceCreate(['id' => $operator->id, 'tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => 'owner password', 'role' => 'owner']);

    $this->withSession([crossHostSessionKey('web') => $user->id])
        ->get(consoleUrl('/'))
        ->assertRedirect(consoleUrl('/login'));
});

it('names the operator for the console guard\'s own key on the console host, the control for the case above', function () {
    $operator = addOperator();

    $this->withSession([crossHostSessionKey('console') => $operator->id, ...freshConsoleSession($operator)])
        ->get(consoleUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenants')->where('operator', 'Pat Operator'));
});

it('refuses an operator\'s console session on every admin route of a tenant host, answering each as a guest is answered: the sign-in page, and the 401 envelope on the JSON endpoint', function () {
    schemaBehind(Tenant::factory()->create(['slug' => 'acme']));
    $operator = addOperator();
    $calls = crossHostAdminCalls();
    $signIn = tenantUrl('acme', '/admin/login');

    // ApiSurfaceTest's admin surface, verb by path, less the door's three rows.
    expect($calls)->toHaveCount(84);

    // Each call reaches the route it names, never the 404 page's catch-all standing in for it.
    foreach ($calls as $row => $call) {
        expect(Route::getRoutes()->match(Request::create(tenantUrl('acme', $call['path']), $call['method']))->getName())->toBe($call['name'], $row);
    }

    $walk = function () use ($calls): array {
        $answers = [];

        foreach ($calls as $row => $call) {
            $answers[$row] = crossHostAnswer($this->call($call['method'], tenantUrl('acme', $call['path'])));
        }

        return $answers;
    };
    $askJson = fn (): array => crossHostAnswer($this->getJson(tenantUrl('acme', '/admin/api/availability')));

    // The guests first: actingAs holds for the rest of the test.
    $guest = $walk();
    $guestJson = $askJson();

    $this->actingAs($operator, 'console');

    $asOperator = $walk();
    $operatorJson = $askJson();

    expect($asOperator)->toBe($guest)
        ->and(array_values(array_unique(array_map(fn (array $answer): string => $answer['status'].' '.$answer['location'], $asOperator))))->toBe(['302 '.$signIn])
        ->and($operatorJson)->toBe($guestJson)
        ->and($operatorJson)->toBe(['status' => 401, 'location' => null, 'json' => ['code' => 'unauthenticated', 'message' => 'unauthenticated', 'data' => ['status' => 401, 'detail' => apiSentence('api.status.unauthenticated')]]]);

    $this->assertGuest('web');
});

it('names nobody on a tenant host for the console guard\'s own key in the session, the cookie were it ever presented there', function () {
    $tenant = schemaBehind(Tenant::factory()->create(['slug' => 'acme']));
    $operator = addOperator();

    // A user of that tenant holding the operator's id, so a guard reading the other's key would
    // find somebody.
    User::query()->forceCreate(['id' => $operator->id, 'tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => 'owner password', 'role' => 'owner']);

    $this->withSession([crossHostSessionKey('console') => $operator->id])
        ->get(tenantUrl('acme', '/admin'))
        ->assertRedirect(tenantUrl('acme', '/admin/login'));
});

it('names the user for the web guard\'s own key on a tenant host, the control for the case above (02c)', function () {
    provisionTenant('acme', ['ownerEmail' => 'owner@acme.example']);

    $answer = crossHostAnswer($this->withSession([crossHostSessionKey('web') => tenantUserId('owner@acme.example')])->get(tenantUrl('acme', '/admin')));

    expect($answer['location'])->not->toBe(tenantUrl('acme', '/admin/login'))
        ->and($answer['status'])->toBeLessThan(400);
});

it('reads the web guard by name on a tenant host and never the default: a user of the tenant the console guard were to hold, made the default as acting as an operator makes it, is nobody there (02g)', function () {
    provisionTenant('acme', ['ownerEmail' => 'owner@acme.example']);
    $user = User::query()->where('email', 'owner@acme.example')->sole();

    Auth::guard('console')->setUser($user);
    Auth::shouldUse('console');

    $this->get(tenantUrl('acme', '/admin'))->assertRedirect(tenantUrl('acme', '/admin/login'));
    $this->assertGuest('web');
});
