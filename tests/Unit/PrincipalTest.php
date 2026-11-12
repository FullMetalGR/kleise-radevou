<?php

use App\Billing\Entitlements;
use App\Http\AdminNav;
use App\Http\AdminProps;
use App\Http\Principal;
use App\Models\User;
use App\Site\SiteLanguage;
use App\Tenancy\Permission;
use App\Tenancy\Role;
use Tests\Support\LangLines;

/*
 * The admin's principal: a signed-in user of the tenant with their role's permissions, or the
 * operator of an open support session with every owner permission but manageBilling and
 * viewOwnCalendar, and the two readers that hand it to the client without a row, the rail and
 * the shared props. The user is built in memory and never saved, so nothing here needs a
 * schema.
 */

function principalUser(int $id, string $name, Role $role): User
{
    return (new User)->forceFill(['id' => $id, 'tenant_id' => 7, 'name' => $name, 'role' => $role]);
}

/**
 * A support session's end, spelled by hand: the unit suite reads no clock.
 */
function principalEnd(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-02 07:00:00', new DateTimeZone('UTC'));
}

/**
 * @param  list<array{
 *     label: string,
 *     url: string,
 *     group: string,
 *     pending: int|null,
 *     current: bool,
 * }>  $rail
 * @return list<string>
 */
function principalRailLabels(array $rail): array
{
    return array_map(fn (array $entry): string => $entry['label'], $rail);
}

/**
 * The owner's row, in its order, less the permissions named: the support set, so an owner
 * permission added later is expected of support unless named here.
 *
 * @return list<Permission>
 */
function principalOwnersLess(Permission ...$withheld): array
{
    return array_values(array_filter(Role::Owner->permissions(), fn (Permission $permission): bool => ! in_array($permission, $withheld, true)));
}

it('answers a user with their role\'s permissions, their id as the actor and as the own staff row\'s user, and no support session', function (Role $role) {
    $principal = Principal::user(principalUser(12, 'Elena Papadaki', $role));

    expect($principal->kind)->toBe(Principal::USER)
        ->and($principal->name)->toBe('Elena Papadaki')
        ->and($principal->role)->toBe($role->value)
        ->and($principal->permissions)->toBe($role->permissions())
        ->and((string) $principal->actor())->toBe('user:12')
        ->and($principal->ownStaffUserId())->toBe(12)
        ->and($principal->supportSessionId())->toBeNull()
        ->and($principal->supportEndsAt())->toBeNull();
})->with(Role::cases());

it('answers support with every owner permission but manageBilling and viewOwnCalendar, the operator as the actor, no own staff row and the session\'s id', function () {
    $principal = Principal::support(31, 4, 'Pat Operator', principalEnd());

    expect($principal->kind)->toBe(Principal::SUPPORT)
        ->and($principal->name)->toBe('Pat Operator')
        ->and($principal->role)->toBe(Principal::SUPPORT)
        ->and($principal->permissions)->toBe(principalOwnersLess(Permission::ViewOwnCalendar, Permission::ManageBilling))
        ->and((string) $principal->actor())->toBe('support:4')
        ->and($principal->actor()->userId())->toBeNull()
        ->and($principal->ownStaffUserId())->toBeNull()
        ->and($principal->supportSessionId())->toBe(31)
        ->and($principal->supportEndsAt())->toEqual(principalEnd());
});

it('carries the language a user chose, users.locale, and none for a user who chose none or for support, which has no user', function (?SiteLanguage $chosen) {
    $user = principalUser(12, 'Elena Papadaki', Role::Staff)->forceFill(['locale' => $chosen]);

    expect(Principal::user($user)->chosenLanguage())->toBe($chosen)
        ->and(Principal::support(31, 4, 'Pat Operator', principalEnd())->chosenLanguage())->toBeNull();
})->with([
    'Greek' => [SiteLanguage::Greek],
    'English' => [SiteLanguage::English],
    'no choice' => [null],
]);

it('derives the support set from the owner\'s row rather than a list of its own, so a permission added reaches support unless it is withheld on purpose by name', function () {
    $support = Principal::support(1, 1, 'Pat Operator', principalEnd())->permissions;
    $withheld = array_values(array_filter(Role::Owner->permissions(), fn (Permission $permission): bool => ! in_array($permission, $support, true)));
    $beyond = array_values(array_filter($support, fn (Permission $permission): bool => ! Role::Owner->holds($permission)));

    expect($withheld)->toBe([Permission::ViewOwnCalendar, Permission::ManageBilling])
        ->and($beyond)->toBe([])
        ->and(Role::Owner->permissions())->toBe(Permission::cases());
});

it('holds a permission exactly when its list names it', function () {
    $support = Principal::support(1, 1, 'Pat Operator', principalEnd());
    $staff = Principal::user(principalUser(3, 'Nikos Georgiou', Role::Staff));

    foreach (Permission::cases() as $permission) {
        expect($support->holds($permission))->toBe(in_array($permission, $support->permissions, true))
            ->and($staff->holds($permission))->toBe(Role::Staff->holds($permission));
    }

    expect($support->holds(Permission::ManageBilling))->toBeFalse()
        ->and($support->holds(Permission::ViewOwnCalendar))->toBeFalse()
        ->and($support->holds(Permission::ManageSettings))->toBeTrue()
        ->and($support->holds(Permission::ManageBookings))->toBeTrue();
});

it('hands support the owner\'s rail without Billing, landing it on Home', function () {
    $translator = LangLines::bind();

    try {
        $support = Principal::support(31, 4, 'Pat Operator', principalEnd());
        $owner = principalRailLabels(AdminNav::for(Role::Owner->permissions(), Entitlements::all(), null, 0, $translator));
        $rail = principalRailLabels(AdminNav::for($support->permissions, Entitlements::all(), null, 0, $translator));
        $named = fn (string ...$entries): array => array_map(fn (string $entry): string => LangLines::line('admin/shell.nav.'.$entry), $entries);

        expect($rail)->toBe($named('home', 'calendar', 'bookings', 'shifts', 'services', 'staff', 'customers', 'reports', 'settings'))
            ->and($rail)->toBe(array_values(array_filter($owner, fn (string $label): bool => ! in_array($label, $named('billing', 'team'), true))))
            ->and(AdminNav::home($support->permissions, Entitlements::all()))->toBe('/admin')
            ->and(AdminNav::home(Role::Staff->permissions(), Entitlements::all()))->toBe('/admin/calendar');
    } finally {
        LangLines::release();
    }
});

it('words auth.user and the support prop in the two shapes: the user as before with no support, support named for the operator with no id', function () {
    $owner = Principal::user(principalUser(12, 'Alex Owner', Role::Owner));
    $support = Principal::support(31, 4, 'Pat Operator', principalEnd());

    $translator = LangLines::bind();

    try {
        expect(AdminProps::userOf($owner, $translator))->toBe(['id' => 12, 'name' => 'Alex Owner', 'role' => 'owner'])
            ->and(AdminProps::userOf($support, $translator))->toBe(['id' => null, 'name' => LangLines::line(AdminProps::SUPPORT_NAME, ['operator' => 'Pat Operator']), 'role' => 'support']);
    } finally {
        LangLines::release();
    }

    expect(AdminProps::supportOf($owner))->toBeNull()
        ->and(AdminProps::supportOf($support))->toBe(['operator' => 'Pat Operator', 'ends_at' => '2026-11-02T07:00:00+00:00']);
});
