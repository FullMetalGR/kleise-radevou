<?php

use App\Http\ConsoleSession;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Site\SiteLanguage;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Cookie;

pest()->use(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/*
 * The door of the admin: a session at the tenant's own origin, opened by an email and a
 * password found within the resolved tenant and nowhere else.
 */

beforeEach(function () {
    provisionTenant('luna', ['name' => 'Luna Hair Studio', 'ownerEmail' => 'owner@luna.example', 'ownerPassword' => 'password']);
    provisionTenant('pier', ['name' => 'Pier Physiotherapy', 'ownerEmail' => 'owner@pier.example', 'ownerPassword' => 'pier-secret']);
});

function lunaOwner(): User
{
    return User::query()->where('email', 'owner@luna.example')->sole();
}

it('renders the sign-in form outside the shell, sharing the tenant, the empty user, the flash and the brand', function () {
    $this->get(tenantUrl('luna', '/admin/login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')
            ->missing('nav')
            ->where('auth.user', null)
            ->where('flash', null)
            ->where('brand', ['name' => siteName()]));
});

it('signs luna\'s owner in with the credentials provisioning gave and lands on /admin, Home', function () {
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])
        ->assertRedirect(tenantUrl('luna', '/admin'));

    $this->assertAuthenticatedAs(lunaOwner());

    $this->get(tenantUrl('luna', '/admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home')->where('auth.user.name', 'Alex Owner'));
});

it('answers a wrong password with one sentence and opens no session', function () {
    $this->from(tenantUrl('luna', '/admin/login'))
        ->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong'])
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionHasErrors(['email' => adminLine(LoginController::REFUSED)]);

    $this->assertGuest();
    $errors = session()->get('errors');

    expect($errors)->toBeInstanceOf(ViewErrorBag::class);
    assert($errors instanceof ViewErrorBag);
    expect($errors->all())->toBe([adminLine(LoginController::REFUSED)]);
});

it('refuses pier\'s owner at luna even with pier\'s right password', function () {
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@pier.example', 'password' => 'pier-secret'])
        ->assertSessionHasErrors(['email' => adminLine(LoginController::REFUSED)]);

    $this->assertGuest();
});

it('answers an empty form with the field sentences and opens no session', function () {
    $this->post(tenantUrl('luna', '/admin/login'), [])
        ->assertSessionHasErrors(['email', 'password']);

    $this->assertGuest();
});

it('keeps the typed email out of the session on the demo, for a wrong password and for a missing one, and flashes it back at any other tenant', function () {
    config(['site.demo_slug' => 'pier']);

    $this->from(tenantUrl('pier', '/admin/login'))
        ->post(tenantUrl('pier', '/admin/login'), ['email' => 'visitor@typed.example', 'password' => 'wrong'])
        ->assertRedirect(tenantUrl('pier', '/admin/login'))
        ->assertSessionHasErrors(['email' => adminLine(LoginController::REFUSED)]);

    expect(session()->hasOldInput())->toBeFalse()
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('visitor@typed.example');

    $this->from(tenantUrl('pier', '/admin/login'))
        ->post(tenantUrl('pier', '/admin/login'), ['email' => 'visitor@typed.example', 'password' => ''])
        ->assertRedirect(tenantUrl('pier', '/admin/login'))
        ->assertSessionHasErrors(['password']);

    expect(session()->hasOldInput())->toBeFalse()
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('visitor@typed.example');
    $this->assertGuest();

    $this->from(tenantUrl('luna', '/admin/login'))
        ->post(tenantUrl('luna', '/admin/login'), ['email' => 'visitor@typed.example', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => adminLine(LoginController::REFUSED)])
        ->assertSessionHasInput('email', 'visitor@typed.example');
});

it('ends the session on sign-out and lands on the sign-in page with a flash', function () {
    $this->actingAs(lunaOwner())
        ->post(tenantUrl('luna', '/admin/logout'))
        ->assertRedirect(tenantUrl('luna', '/admin/login'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => adminLine(LoginController::SIGNED_OUT)]);

    $this->assertGuest();
});

it('redirects an unauthenticated request for an admin page to the sign-in page', function (string $path) {
    $this->get(tenantUrl('luna', $path))->assertRedirect(tenantUrl('luna', '/admin/login'));
})->with(['/admin', '/admin/my-calendar', '/admin/nothing-here', '/admin/a/b/c']);

it('does not honour a session of pier\'s user at luna', function () {
    $pierOwner = User::query()->where('email', 'owner@pier.example')->sole();

    $this->actingAs($pierOwner)->get(tenantUrl('luna', '/admin'))->assertRedirect(tenantUrl('luna', '/admin/login'));
});

it('sends a signed-in visitor of the sign-in page on to their first screen', function () {
    $this->actingAs(lunaOwner())->get(tenantUrl('luna', '/admin/login'))->assertRedirect(tenantUrl('luna', '/admin'));
});

it('lands a staff user on the Calendar, the one entry of their rail', function () {
    addTenantUser('staff@luna.example', 'staff');

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'staff@luna.example', 'password' => 'correct horse battery staple'])
        ->assertRedirect(tenantUrl('luna', '/admin/calendar'));
    $this->get(tenantUrl('luna', '/admin/login'))->assertRedirect(tenantUrl('luna', '/admin/calendar'));
});

it('writes the session cookie with no domain attribute, host-only', function () {
    $response = $this->get(tenantUrl('luna', '/admin/login'));
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($cookie)->toBeInstanceOf(Cookie::class);
    assert($cookie instanceof Cookie);
    expect($cookie->getDomain())->toBeNull()
        ->and(config('session.domain'))->toBeNull();
});

it('answers the sign-in POST 429 with a worded refusal past ten a minute from one address', function () {
    foreach (range(1, 10) as $attempt) {
        $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong'])->assertRedirect();
    }

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('refusal', adminLine(LoginController::TOO_MANY)));

    $this->assertGuest();
});

it('words the door\'s sentences in Greek at a tenant whose default is el: no password, a wrong one, the limit passed, and the sign-out\'s flash to a user at el', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => null]);
    DB::table('users')->where('email', 'owner@luna.example')->update(['locale' => SiteLanguage::Greek->value]);
    $greek = fn (string $key): string => adminLine($key, [], SiteLanguage::Greek);
    $keys = [LoginRequest::MESSAGES['password.required'], LoginController::REFUSED, LoginController::TOO_MANY, LoginController::SIGNED_OUT];

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example'])
        ->assertSessionHasErrors(['password' => $greek(LoginRequest::MESSAGES['password.required'])]);
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => $greek(LoginController::REFUSED)]);

    // The limit's ten a minute: the two above and eight more, the eleventh refused.
    foreach (range(1, 8) as $attempt) {
        $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'wrong'])->assertRedirect();
    }

    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => 'password'])
        ->assertStatus(429)
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('refusal', $greek(LoginController::TOO_MANY)));
    $this->assertGuest();

    $this->actingAs(lunaOwner())->post(tenantUrl('luna', '/admin/logout'))
        ->assertSessionHas('flash', ['kind' => 'success', 'message' => $greek(LoginController::SIGNED_OUT)]);

    expect(array_map(fn (string $key): bool => $greek($key) !== adminLine($key), $keys))->toBe([true, true, true, true]);
});

it('words the door\'s missing email, an email that is no string and a password that is no string in Greek at a tenant whose default is el', function () {
    DB::table('tenants')->where('slug', 'luna')->update(['locale' => SiteLanguage::Greek->value]);
    DB::table('tenant_luna.settings')->where('id', 1)->update(['language' => null]);
    $greek = fn (string $key): string => adminLine($key, [], SiteLanguage::Greek);
    $keys = [LoginRequest::MESSAGES['email.required'], LoginRequest::MESSAGES['email.string'], LoginRequest::MESSAGES['password.string']];

    $this->post(tenantUrl('luna', '/admin/login'), ['password' => 'password'])
        ->assertSessionHasErrors(['email' => $greek($keys[0])]);
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => ['owner@luna.example'], 'password' => 'password'])
        ->assertSessionHasErrors(['email' => $greek($keys[1])]);
    $this->post(tenantUrl('luna', '/admin/login'), ['email' => 'owner@luna.example', 'password' => ['password']])
        ->assertSessionHasErrors(['password' => $greek($keys[2])]);

    expect(array_map(fn (string $key): bool => $greek($key) !== adminLine($key), $keys))->toBe([true, true, true]);
});

it('limits nothing but the sign-in POST', function () {
    foreach (range(1, 15) as $attempt) {
        $this->get(tenantUrl('luna', '/admin/login'))->assertOk();
    }
});

it('boxes a refused sign-in at one second on both doors, past one bcrypt verify at the twelve rounds production runs, so an unknown email answers no sooner than a wrong password (p8f-09)', function () {
    // Read from the configuration and from the guards the framework built from it, never timed:
    // one verify at twelve rounds took about 220 ms on the dev machine, and one at fourteen
    // about 860, both inside the box; a right password is let out of the box at once.
    expect(config('auth.timebox_duration'))->toBe(1_000_000);

    foreach (['web', ConsoleSession::GUARD] as $name) {
        $guard = Auth::guard($name);

        expect($guard)->toBeInstanceOf(SessionGuard::class)
            ->and((new ReflectionProperty(SessionGuard::class, 'timeboxDuration'))->getValue($guard))->toBe(1_000_000);
    }
});
