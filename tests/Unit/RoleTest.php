<?php

use App\Tenancy\Role;

/*
 * The mapping from a role to the one permission asked about here.
 *
 * manageBookings is owner and manager, and never staff. The customer's email and phone hang on
 * it, on every surface, and a message is a surface, so this is the decision the business notice
 * and the decision page both ask rather than each naming the two roles it meant.
 */

it('holds manageBookings for the roles that run the diary and for no other', function (Role $role, bool $may) {
    expect($role->mayManageBookings())->toBe($may);
})->with([
    'owner' => [Role::Owner, true],
    'manager' => [Role::Manager, true],
    'staff' => [Role::Staff, false],
]);

it('names exactly the two roles that run the diary, so a role added later cannot inherit the permission', function () {
    // Read off the enum rather than listed by hand: a fourth role appearing without a decision
    // is what this is watching for, and the match it would have to be added to is exhaustive
    // rather than defaulted, so the answer is never a silent yes.
    expect(array_values(array_filter(Role::cases(), fn (Role $role): bool => $role->mayManageBookings())))
        ->toBe([Role::Owner, Role::Manager]);
});

/*
 * And the permission the decision link turns on.
 *
 * approveBookings goes to all three roles, a staff user's scoped to bookings with an item on
 * their own staff row. The scope is a fact about one booking and is checked where the booking
 * is known; what a role can answer on its own is whether it may decide at all, and every role
 * may. Approve carries its own permission, and a staff user without manageBookings holds it.
 */

it('holds approveBookings for every role, because deciding a request is not the same permission as running the diary', function (Role $role) {
    expect($role->mayApproveBookings())->toBeTrue();
})->with([
    'owner' => [Role::Owner],
    'manager' => [Role::Manager],
    'staff' => [Role::Staff],
]);

it('answers approveBookings for every case of the enum, so a role added later has to say whether it decides', function () {
    // The answer is yes for all three today, which is exactly why the match behind it is
    // exhaustive rather than a default: a fourth role must be written into it, and until
    // somebody does the build does not compile a silent yes.
    expect(array_values(array_filter(Role::cases(), fn (Role $role): bool => $role->mayApproveBookings())))
        ->toBe(Role::cases());
});
