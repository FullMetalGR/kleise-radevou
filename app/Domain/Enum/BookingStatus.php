<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The nine states of the status machine, spelled as the bookings.status column stores them. The
 * CHECK constraint on that column lists the same nine, and a test holds the two lists to each
 * other, so a state added on one side without the other fails visibly.
 */
enum BookingStatus: string
{
    case Pending = 'pending';
    case AwaitingApproval = 'awaiting_approval';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * The three states in which a booking is a hold: it occupies its slot on a TTL and the
     * clock frees it when hold_expires_at passes. These are the states the blocking predicate
     * pairs with a time comparison, and the states the reap and the sweeper look for.
     *
     * @return non-empty-list<self>
     */
    public static function held(): array
    {
        return [self::Pending, self::AwaitingApproval, self::AwaitingPayment];
    }

    public function isHeld(): bool
    {
        return in_array($this, self::held(), true);
    }

    /**
     * Whether a booking in this state is a request still in front of a human: the customer has
     * asked, nobody has answered, and nothing is booked yet. It is what decides how the
     * customer's messages name the thing: a request is withdrawn or moved, a booking is
     * cancelled or moved, and telling somebody their appointment was cancelled when no
     * appointment ever existed is a sentence false of the case it is printed for.
     */
    public function awaitsADecision(): bool
    {
        return $this === self::AwaitingApproval;
    }

    /**
     * Whether a booking in this state has stopped occupying its slot for good, which is what
     * decides whether a transition into it clears the blocking flag on the items. These are
     * the states the blocking predicate never counts, whatever the clock says: it counts
     * confirmed always and a held state until its expiry passes, so confirmed keeps the slot
     * and the five here let it go.
     *
     * Spelled as a match over all nine rather than as a list, so a tenth state cannot be added
     * without somebody saying which side of this it falls on.
     */
    public function releasesTheSlot(): bool
    {
        return match ($this) {
            self::Pending, self::AwaitingApproval, self::AwaitingPayment, self::Confirmed => false,
            self::Completed, self::NoShow, self::Cancelled, self::Rejected, self::Expired => true,
        };
    }

    /**
     * Whether a booking in this state came to nothing: cancelled by either side, rejected by the
     * business, or lapsed while it was held, so the time it named was never kept. Completed and
     * no-show kept their time, with the customer or without, and the four live states are still
     * on their way. A My bookings session's prefill reads past the three.
     *
     * A match over all nine, as releasesTheSlot is, so a tenth state is placed on one side.
     */
    public function cameToNothing(): bool
    {
        return match ($this) {
            self::Pending, self::AwaitingApproval, self::AwaitingPayment, self::Confirmed, self::Completed, self::NoShow => false,
            self::Cancelled, self::Rejected, self::Expired => true,
        };
    }
}
