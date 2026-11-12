<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\ApproveBooking;
use App\Application\Clock;
use App\Application\Dto\BookingRecord;
use App\Application\MarkBookingOutcome;
use App\Application\RejectBooking;
use App\Domain\Booking\Actor;
use App\Domain\Booking\CancellationPolicy;
use App\Tenancy\Permission;
use App\Tenancy\Tenant;

/**
 * The actions a viewer may take on one booking right now, per booking and per viewer: the one
 * place the booking dialog's guards are spelled on the server, and the list the client renders
 * verbatim. The client holds no copy of the status machine; a button this list did not name is a
 * button the handler would have refused anyway, and every handler refuses again, because the
 * list was true at render and the press comes later.
 *
 * Each action is asked of the function its use case's guard asks, so the list cannot hold a
 * second idea of the rule: approve and reject of the status and the database's verdict on the
 * hold, the window binding on both surfaces; cancel of the status rule, which is all of the
 * policy for the business; completed and no-show of the status, the first start and the Clock
 * the outcome's guard reads. Beside the guards, the viewer's permissions, which are the
 * principal's, a user's role's or support's, and the staff scope, ApproverScope, which a viewer
 * without manageBookings is held to.
 *
 * The wire spells each action as the last segment of its route, in the order the footer shows
 * them: approve, reject, cancel, complete, no-show. An empty list is a viewer who can do nothing
 * to this booking now, and the dialog renders no action bar for it.
 */
final readonly class BookingActions
{
    public const string APPROVE = 'approve';

    public const string REJECT = 'reject';

    public const string CANCEL = 'cancel';

    public const string COMPLETE = 'complete';

    public const string NO_SHOW = 'no-show';

    public function __construct(
        private ApproverScope $scope,
        private Clock $clock,
    ) {}

    /**
     * @return list<string>
     */
    public function for(Tenant $tenant, BookingRecord $record, Principal $viewer): array
    {
        $booking = $record->snapshot;
        $actions = [];

        if ($this->decides($tenant, $record, $viewer)) {
            if (ApproveBooking::refusal($booking->status, $record->holdIsLive) === null) {
                $actions[] = self::APPROVE;
            }

            if (RejectBooking::refusal($booking->status, $record->holdIsLive) === null) {
                $actions[] = self::REJECT;
            }
        }

        if (! $viewer->holds(Permission::ManageBookings)) {
            return $actions;
        }

        if (CancellationPolicy::cancelRefusalByStatus($booking->status) === null) {
            $actions[] = self::CANCEL;
        }

        if (MarkBookingOutcome::refusal($booking->status, $booking->startsAt(), $this->clock->now()) === null) {
            $actions[] = self::COMPLETE;
            $actions[] = self::NO_SHOW;
        }

        return $actions;
    }

    /**
     * Whether this viewer may move this booking now, the fact the calendar's blocks and the
     * dialog's booking carry as `movable`: whoever may cancel may move, so manageBookings, and
     * the move's own status rule for the business, asked of the one function its guard asks, a
     * confirmed booking or a request or payment hold still live and never a pending checkout
     * hold. Asks nothing of the database, so a window of blocks costs nothing more.
     */
    public static function movable(BookingRecord $record, Principal $viewer): bool
    {
        return $viewer->holds(Permission::ManageBookings)
            && CancellationPolicy::rescheduleRefusalByStatus($record->snapshot->status, $record->holdIsLive, Actor::Admin) === null;
    }

    /**
     * Whether the viewer may decide this booking at all, asked only where a decision is still
     * possible, so the staff scope's query runs for a request and not for every booking opened.
     */
    private function decides(Tenant $tenant, BookingRecord $record, Principal $viewer): bool
    {
        if (ApproveBooking::refusal($record->snapshot->status, $record->holdIsLive) !== null
            && RejectBooking::refusal($record->snapshot->status, $record->holdIsLive) !== null) {
            return false;
        }

        return $this->scope->admitsPrincipal($tenant, $record->snapshot->uuid, $viewer);
    }
}
