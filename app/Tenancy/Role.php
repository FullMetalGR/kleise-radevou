<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * What a user may do inside their tenant. Never what the tenant bought, which is an entitlement.
 * Mirrors the CHECK constraint on users.role.
 */
enum Role: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /**
     * The permissions this role holds, resolved here on the server and nowhere else. Each
     * role's row is written out rather than derived from another's, so the table reads here
     * whole, and a fourth role is a row somebody decides.
     *
     * The staff row's approveBookings carries a scope, bookings with an item on their own staff
     * row, which is a fact about one booking and is answered where the booking is known.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Manager => [
                Permission::ViewBookings, Permission::ViewOwnCalendar, Permission::ManageBookings,
                Permission::ApproveBookings, Permission::ManageSettings,
            ],
            self::Staff => [Permission::ViewOwnCalendar, Permission::ApproveBookings],
        };
    }

    public function holds(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Whether this role holds manageBookings, the permission owner and manager hold and staff
     * does not.
     *
     * It is asked wherever something hangs on that permission, and a message is such a place:
     * the customer's email and phone reach a recipient only where the recipient has it, so the
     * notice a staff member is sent says whose booking it is and not how to reach them. One
     * decision, asked by every surface, rather than three surfaces each spelling out which two
     * roles it meant.
     */
    public function mayManageBookings(): bool
    {
        return match ($this) {
            self::Owner, self::Manager => true,
            self::Staff => false,
        };
    }

    /**
     * Whether this role holds approveBookings, the permission all three roles hold, kept
     * separate from manageBookings: a staff member who approves their own bookings does not
     * thereby edit the catalog, and a manager does not silently acquire approval rights nobody
     * granted.
     *
     * The scope the table writes beside the staff row, "bookings with an item on their own
     * staff row", is not here. It is a fact about one booking and is answered where the booking
     * is known; what a role can answer alone is whether it may decide at all.
     *
     * Every answer is yes, which is exactly why the match is exhaustive rather than a default:
     * a fourth role has to be written into it by somebody deciding, and a permission that
     * defaults open is not one.
     */
    public function mayApproveBookings(): bool
    {
        return match ($this) {
            self::Owner, self::Manager, self::Staff => true,
        };
    }
}
