<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\BookingStatus;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;

/**
 * Which choices a principal is offered over the bookings inside a closure, so that adding a
 * closure hands no role more than its own routes give it. Keep needs nothing beyond adding the
 * closure. Move needs manageBookings, which the move itself declares. Cancel needs, for every
 * booking, what cancelling or rejecting that one booking needs on its own: manageBookings to
 * cancel a confirmed booking or one awaiting its payment, and to reject a request, approveBookings
 * within the principal's approver scope.
 *
 * The same answer lists the choices beside the bookings and refuses a choice that is not among
 * them, so a button the admin hides is a press the server refuses.
 */
final readonly class ClosureChoices
{
    public function __construct(private ApproverScope $scope) {}

    /**
     * @param  list<array{uuid: string, status: string}>  $bookings
     * @return list<ClosureChoice>
     */
    public function offered(Tenant $tenant, Principal $principal, array $bookings): array
    {
        $choices = [ClosureChoice::Keep];

        if ($principal->holds(Permission::ManageBookings)) {
            $choices[] = ClosureChoice::Move;
        }

        if ($this->mayCancelAll($tenant, $principal, $bookings)) {
            $choices[] = ClosureChoice::Cancel;
        }

        return $choices;
    }

    /**
     * @param  list<array{uuid: string, status: string}>  $bookings
     */
    private function mayCancelAll(Tenant $tenant, Principal $principal, array $bookings): bool
    {
        foreach ($bookings as $booking) {
            $allowed = $booking['status'] === BookingStatus::AwaitingApproval->value
                ? $this->scope->admitsPrincipal($tenant, $booking['uuid'], $principal)
                : $principal->holds(Permission::ManageBookings);

            if (! $allowed) {
                return false;
            }
        }

        return true;
    }
}
