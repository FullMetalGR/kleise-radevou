<?php

use App\Http\Controllers\Admin\LoginController as AdminLoginController;
use App\Http\Controllers\Console\LoginController;
use App\Platform\Operator;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/*
 * The console's door and what stands behind it: a guard of its own, console, over the operators
 * provider, the default guard staying the admin's web. The door signs an operator in on the
 * console host and lands on the home, refuses every wrong pair with one sentence and no session,
 * and is limited to ten a minute from one address; the sign-out ends the session with a toast.
 * Every other console route runs the operator check: the home naming the operator, and an
 * unknown path the console's own 404 inside its shell for an operator and the door for anyone
 * else.
 */

it('configures the console guard as a session over the operators provider beside the admin\'s, the default guard staying web', function () {
    expect(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.guards.console'))->toBe(['driver' => 'session', 'provider' => 'operators'])
        ->and(config('auth.providers.operators'))->toBe(['driver' => 'eloquent', 'model' => Operator::class])
        ->and(config('auth.guards.web'))->toBe(['driver' => 'session', 'provider' => 'users']);
});

it('signs an operator in with their email and password and lands on the console\'s home, the tenant list', function () {
    $operator = addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));

    $this->assertAuthenticatedAs($operator, 'console');
    $this->assertGuest('web');

    $this->get(consoleUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Tenants')->where('operator', 'Pat Operator'));
});

it('takes the email whatever its case, as the column compares it', function () {
    $operator = addOperator();

    $this->post(consoleUrl('/login'), ['email' => 'Pat@Inbox.EXAMPLE', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));

    $this->assertAuthenticatedAs($operator, 'console');
});

it('answers {case} with the one sentence on the email field and opens no session', function (string $email, string $password) {
    addOperator();

    $this->from(consoleUrl('/login'))
        ->post(consoleUrl('/login'), ['email' => $email, 'password' => $password])
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHasErrors(['email' => LoginController::REFUSED]);

    $this->assertGuest('console');
    $errors = session()->get('errors');

    expect($errors)->toBeInstanceOf(ViewErrorBag::class);
    assert($errors instanceof ViewErrorBag);
    expect($errors->all())->toBe([LoginController::REFUSED]);
})->with([
    'a wrong password' => ['pat@inbox.example', 'not the password'],
    'an email no operator holds' => ['nobody@inbox.example', 'operator password'],
]);

it('refuses a tenant user\'s own pair at the console, since the guard reads the operators alone', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Alex Owner', 'email' => 'owner@acme.example', 'password' => bcrypt('owner password'), 'role' => 'owner']);

    $this->post(consoleUrl('/login'), ['email' => 'owner@acme.example', 'password' => 'owner password'])
        ->assertSessionHasErrors(['email' => LoginController::REFUSED]);

    $this->assertGuest('console');
    $this->assertGuest('web');
});

it('answers an empty form with the field sentences and opens no session', function () {
    $this->post(consoleUrl('/login'), [])->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest('console');
});

it('hands a refusal back to the door on the email field through the errors the adapter shares', function () {
    addOperator();

    $this->followingRedirects()
        ->from(consoleUrl('/login'))
        ->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'not the password'])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('console/Login')->where('errors', ['email' => LoginController::REFUSED])->where('operator', null));
});

it('answers the sign-in POST 429 with the worded form past ten a minute from one address, the door still naming the brand, and opens no session', function () {
    addOperator();

    foreach (range(1, 10) as $attempt) {
        $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'not the password'])->assertRedirect();
    }

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertStatus(429)
        ->assertViewIs('console.app')
        ->assertInertia(fn (Assert $page) => $page->component('console/Login')->where('refusal', LoginController::TOO_MANY)->where('brand', ['name' => siteName()]));

    $this->assertGuest('console');
});

it('words the limit\'s refusal and the sign-out in sentences of the console\'s own, apart from the admin\'s door (02e)', function () {
    expect(LoginController::TOO_MANY)->toBe('Too many sign-in attempts from this address. Please wait a minute and try again.')
        ->and(LoginController::SIGNED_OUT)->toBe('You have signed out.');
});

it('counts the console\'s door on a budget of its own, apart from the admin\'s', function () {
    schemaBehind(Tenant::factory()->create(['slug' => 'acme']));

    foreach (range(1, 10) as $attempt) {
        $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'not the password'])->assertRedirect();
    }

    $this->post(tenantUrl('acme', '/admin/login'), ['email' => 'owner@acme.example', 'password' => 'not the password'])
        ->assertSessionHasErrors(['email' => adminLine(AdminLoginController::REFUSED)]);
});

it('limits nothing but the sign-in POST', function () {
    foreach (range(1, 15) as $attempt) {
        $this->get(consoleUrl('/login'))->assertOk();
    }
});

it('sends a signed-in operator who asks for the door on to the home', function () {
    $this->actingAs(addOperator(), 'console')
        ->get(consoleUrl('/login'))
        ->assertRedirect(consoleUrl('/'));
});

it('brings a visitor the door turned away from {path} back to that page once signed in', function (string $path) {
    addOperator();

    $this->get(consoleUrl($path))->assertRedirect(consoleUrl('/login'));
    $this->get(consoleUrl('/login'))->assertOk();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl($path));
})->with(['/tenants/new', '/audit?tenant=luna', '/messages', '/nothing-here']);

it('lands a sign-in from the door itself on the home, no page having been asked for before it', function () {
    addOperator();

    $this->get(consoleUrl('/login'))->assertOk();

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));
});

it('remembers no write a visitor posted, a write being no page: the sign-in after it lands on the home', function () {
    addOperator();

    $this->post(consoleUrl('/tenants/luna/resume'))->assertRedirect(consoleUrl('/login'));

    $this->post(consoleUrl('/login'), ['email' => 'pat@inbox.example', 'password' => 'operator password'])
        ->assertRedirect(consoleUrl('/'));
});

it('ends the session on sign-out and lands on the door with a flash', function () {
    $this->actingAs(addOperator(), 'console')
        ->post(consoleUrl('/logout'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => LoginController::SIGNED_OUT]);

    $this->assertGuest('console');

    $this->get(consoleUrl('/'))->assertRedirect(consoleUrl('/login'));
});

it('signs out whoever posts it, a visitor never signed in landing on the door too', function () {
    $this->post(consoleUrl('/logout'))
        ->assertRedirect(consoleUrl('/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => LoginController::SIGNED_OUT]);
});

it('renders the home naming the operator, sharing the flash, the operator, the errors, the console\'s rail, its zone and the brand and nothing a tenant page shares', function () {
    $response = $this->actingAs(addOperator(), 'console')
        ->withSession(freshConsoleSession())
        ->get(consoleUrl('/'))
        ->assertOk()
        ->assertViewIs('console.app');

    $response->assertInertia(fn (Assert $page) => $page->component('console/Tenants')->where('operator', 'Pat Operator')->where('flash', null)->where('zone', 'Europe/Athens'));
    $page = $response->viewData('page');

    assert(is_array($page) && is_array($page['props']));

    expect(array_keys($page['props']))->toEqualCanonicalizing(['errors', 'flash', 'operator', 'nav', 'zone', 'brand', 'filters', 'plan_names', 'tenants', 'strings'])
        ->and($page['props']['brand'])->toBe(['name' => siteName()]);
});

it('sends a visitor with no operator session from the home to the door', function () {
    $this->get(consoleUrl('/'))->assertRedirect(consoleUrl('/login'));
});

it('answers an unknown path with the console\'s 404 page inside its shell for an operator, {path}', function (string $path) {
    $this->actingAs(addOperator(), 'console')
        ->withSession(freshConsoleSession())
        ->get(consoleUrl($path))
        ->assertNotFound()
        ->assertViewIs('console.app')
        ->assertInertia(fn (Assert $page) => $page->component('console/NotFound')->where('operator', 'Pat Operator'));
})->with(['/nothing-here', '/a/b/c', '/admin', '/admin/login', '/probe', '/embed.js', '/api/v1/config']);

it('sends a visitor with no operator session from an unknown path to the door, {path}', function (string $path) {
    $this->get(consoleUrl($path))->assertRedirect(consoleUrl('/login'));
})->with(['/nothing-here', '/a/b/c', '/admin', '/probe']);
