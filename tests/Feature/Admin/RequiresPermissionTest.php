<?php

use App\Http\AdminSession;
use App\Http\Middleware\RequiresPermission;
use App\Models\User;
use App\Platform\SupportSessions;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Permission;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Answer;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/*
 * RequiresPermission at the middleware level: one declaration on a route may name more than
 * one permission, and handle() admits a user holding any of them and refuses one holding none.
 * The role table makes a user holding neither manageBookings nor viewOwnCalendar unreachable
 * over HTTP, since staff holds the second, so the refusal is proven with a declaration the
 * table does split, manageSettings and manageBilling, of which a manager holds one and a staff
 * member neither; the one admin JSON endpoint's own declaration is walked for the two roles
 * that reach it. Nothing here needs a schema: the tenant and the users are built in memory and
 * never saved, and the middleware is constructed by hand over a bound tenant. The requests
 * carry no session, so the support read finds no key and asks no row.
 */

/**
 * The slugs the router hands handle() for a declaration as a route spells it: the string
 * after the colon, split on the comma, which is how the pipeline parses every middleware
 * string it carries.
 *
 * @return list<string>
 */
function declaredSlugs(string $declaration): array
{
    [, $parameters] = explode(':', $declaration, 2);

    return explode(',', $parameters);
}

function permissionMiddleware(): RequiresPermission
{
    $current = new CurrentTenant;
    $current->bind((new Tenant)->forceFill(['id' => 7, 'slug' => 'luna']));

    return new RequiresPermission(new AdminSession($current, app(SupportSessions::class)));
}

function permissionRequest(?User $user, bool $json): Request
{
    $request = Request::create('https://luna.kleiseradevou.test/admin/api/availability', 'GET', server: $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

function permissionUser(Role $role): User
{
    return (new User)->forceFill(['id' => 1, 'tenant_id' => 7, 'name' => 'Alex Owner', 'role' => $role]);
}

function permissionNext(): Closure
{
    return fn (Request $request): Response => new Response('through');
}

it('admits a user holding any one of the permissions a declaration names', function (Role $role, string $declaration) {
    $answer = permissionMiddleware()->handle(permissionRequest(permissionUser($role), true), permissionNext(), ...declaredSlugs($declaration));

    expect($answer->getContent())->toBe('through');
})->with([
    'a manager under manageBookings,viewOwnCalendar, holding both' => [Role::Manager, RequiresPermission::of(Permission::ManageBookings, Permission::ViewOwnCalendar)],
    'a staff member under manageBookings,viewOwnCalendar, holding the second alone' => [Role::Staff, RequiresPermission::of(Permission::ManageBookings, Permission::ViewOwnCalendar)],
    'a manager under manageSettings,manageBilling, holding the first alone' => [Role::Manager, RequiresPermission::of(Permission::ManageSettings, Permission::ManageBilling)],
    'a manager under manageBilling,manageSettings, holding the second alone' => [Role::Manager, RequiresPermission::of(Permission::ManageBilling, Permission::ManageSettings)],
    'an owner under manageBilling alone' => [Role::Owner, RequiresPermission::of(Permission::ManageBilling)],
]);

it('refuses a signed-in user holding none of the permissions a declaration names, 403', function (Role $role, string $declaration) {
    expect(fn (): Answer => permissionMiddleware()->handle(permissionRequest(permissionUser($role), true), permissionNext(), ...declaredSlugs($declaration)))
        ->toThrow(AccessDeniedHttpException::class);
})->with([
    'a staff member under manageSettings,manageBilling' => [Role::Staff, RequiresPermission::of(Permission::ManageSettings, Permission::ManageBilling)],
    'a staff member under manageBilling alone' => [Role::Staff, RequiresPermission::of(Permission::ManageBilling)],
    'a manager under manageBilling alone' => [Role::Manager, RequiresPermission::of(Permission::ManageBilling)],
]);

it('answers a caller with no session: JSON asked for is 401, which the envelope renders as its sentence, and a page is the sign-in redirect on the request\'s own host', function () {
    expect(fn (): Answer => permissionMiddleware()->handle(permissionRequest(null, true), permissionNext(), ...declaredSlugs(RequiresPermission::of(Permission::ManageBookings, Permission::ViewOwnCalendar))))
        ->toThrow(AuthenticationException::class);

    $answer = permissionMiddleware()->handle(permissionRequest(null, false), permissionNext(), ...declaredSlugs(RequiresPermission::of(Permission::ManageBookings, Permission::ViewOwnCalendar)));

    expect($answer->getStatusCode())->toBe(302)
        ->and($answer->headers->get('Location'))->toBe('https://luna.kleiseradevou.test/admin/login');
});

it('spells a declaration of several permissions as one middleware string, hands the router those slugs, and reads them back as declared', function () {
    expect(RequiresPermission::of(Permission::ManageBookings, Permission::ViewOwnCalendar))->toBe('permission:manageBookings,viewOwnCalendar')
        ->and(declaredSlugs('permission:manageBookings,viewOwnCalendar'))->toBe(['manageBookings', 'viewOwnCalendar'])
        ->and(RequiresPermission::named('permission:manageBookings,viewOwnCalendar'))->toBe([Permission::ManageBookings, Permission::ViewOwnCalendar])
        ->and(RequiresPermission::named(RequiresPermission::of(Permission::ManageSettings)))->toBe([Permission::ManageSettings]);
});
