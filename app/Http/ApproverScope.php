<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Notification\BusinessDirectories;
use App\Notifications\StaffOnBooking;
use App\Tenancy\Permission;
use App\Tenancy\Role;
use App\Tenancy\Tenant;

/**
 * Whether a user of this tenant may decide one booking: the second and third of the three checks,
 * the role may approve, and a user without manageBookings has an item on their own staff row, the
 * row whose user_id is theirs. The first check, that the user exists here, is the caller's: the
 * decision link reads the tenant's users, the admin's session reads its own principal back on
 * every request.
 *
 * Two doors onto one rule. The signed link carries its own user from the signature and asks by the
 * role; the admin asks by the principal, whose permissions are a user's role's or support's.
 * Support holds manageBookings, which admits before any row is read, and has no staff row of its
 * own, so the scope never narrows it.
 *
 * One answer for the two surfaces an owner decides from, the signed link and the admin's
 * buttons, and for the admin's list of the actions allowed now, so the scope that hides a button
 * is the scope that refuses the press behind it. Every surface runs it on every request, because
 * a role can change and a staff row be reassigned between a render and a press.
 *
 * The staff scope's one query is the recipient rule's own, BusinessDirectory::staffOn: the staff
 * rows with an item on the booking, which is also who is told there is something to decide. A
 * booking that does not exist has no staff rows, so it is out of every staff user's scope.
 */
final readonly class ApproverScope
{
    public function __construct(private BusinessDirectories $directories) {}

    /**
     * The signed decision link's door: the user the signature names, by their role.
     */
    public function admits(Tenant $tenant, string $uuid, Role $role, int $userId): bool
    {
        return $this->scopes($tenant, $uuid, $role->permissions(), $userId);
    }

    /**
     * The admin's door: the principal the session read on this request.
     */
    public function admitsPrincipal(Tenant $tenant, string $uuid, Principal $principal): bool
    {
        return $this->scopes($tenant, $uuid, $principal->permissions, $principal->ownStaffUserId());
    }

    /**
     * @param  list<Permission>  $permissions
     * @param  int|null  $ownStaffUserId  the user whose staff row is the decider's own, null for none
     */
    private function scopes(Tenant $tenant, string $uuid, array $permissions, ?int $ownStaffUserId): bool
    {
        if (! in_array(Permission::ApproveBookings, $permissions, true)) {
            return false;
        }

        if (in_array(Permission::ManageBookings, $permissions, true)) {
            return true;
        }

        return $ownStaffUserId !== null && self::hasAnItemOn($this->directories->for($tenant)->staffOn($uuid), $ownStaffUserId);
    }

    /**
     * @param  list<StaffOnBooking>  $staff
     */
    private static function hasAnItemOn(array $staff, int $userId): bool
    {
        foreach ($staff as $member) {
            if ($member->userId === $userId) {
                return true;
            }
        }

        return false;
    }
}
