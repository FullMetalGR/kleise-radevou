<?php

use App\Tenancy\Permission;
use App\Tenancy\Role;

/*
 * The role-to-permission table, cell for cell. The client reads these slugs and never the role.
 */

it('names the six permissions by their slugs', function () {
    expect(array_map(fn (Permission $p): string => $p->value, Permission::cases()))->toBe([
        'viewBookings', 'viewOwnCalendar', 'manageBookings', 'approveBookings', 'manageSettings', 'manageBilling',
    ]);
});

it('maps each role to its permissions', function (Role $role, array $expected) {
    expect(array_map(fn (Permission $p): string => $p->value, $role->permissions()))->toBe($expected);
})->with([
    'owner' => [Role::Owner, ['viewBookings', 'viewOwnCalendar', 'manageBookings', 'approveBookings', 'manageSettings', 'manageBilling']],
    'manager' => [Role::Manager, ['viewBookings', 'viewOwnCalendar', 'manageBookings', 'approveBookings', 'manageSettings']],
    'staff' => [Role::Staff, ['viewOwnCalendar', 'approveBookings']],
]);

it('agrees with the two predicates the notifications already ask', function (Role $role) {
    expect($role->holds(Permission::ManageBookings))->toBe($role->mayManageBookings())
        ->and($role->holds(Permission::ApproveBookings))->toBe($role->mayApproveBookings());
})->with([Role::Owner, Role::Manager, Role::Staff]);

it('maps every case of the role enum, cell for cell, so a role added or a permission moved fails here', function () {
    // Read off the enum rather than a dataset: a dataset names the roles it knows and a fourth
    // role would pass it unasked, while this walk puts every case on the table and the table is
    // pinned whole.
    $map = [];

    foreach (Role::cases() as $role) {
        $map[$role->value] = array_map(fn (Permission $p): string => $p->value, $role->permissions());
    }

    expect($map)->toBe([
        'owner' => ['viewBookings', 'viewOwnCalendar', 'manageBookings', 'approveBookings', 'manageSettings', 'manageBilling'],
        'manager' => ['viewBookings', 'viewOwnCalendar', 'manageBookings', 'approveBookings', 'manageSettings'],
        'staff' => ['viewOwnCalendar', 'approveBookings'],
    ]);
});
