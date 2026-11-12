<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The columns of the booking row a guarded transition may write beside the status, spelled as
 * the table holds them.
 *
 * A transition is described as data and the columns it writes are part of that description, so
 * this is the whole vocabulary of what one may set: a compare-and-set is built from cases of
 * this enum and never from a string a caller chose, which is what keeps a column name out of
 * the reach of anything that could be handed one.
 *
 * HoldClass and HoldExpiresAt go together: settling a booking ends its hold, so the class and
 * the expiry are nulled by every transition out of a held state. ApprovedAt, ApprovedBy and
 * RejectionReason belong to the owner's decision on an approval request, and are written by the
 * transition that makes it and by no other. PaymentRef belongs to the paid transition.
 */
enum TransitionColumn: string
{
    case HoldClass = 'hold_class';

    case HoldExpiresAt = 'hold_expires_at';

    /** When the owner said yes. Null on a booking nobody has approved. */
    case ApprovedAt = 'approved_at';

    /**
     * Which user said yes, by id, null where the decision was not a person's: the sweeper
     * granting a timed-out request in the owner's favour has nobody to name and says so with a
     * null rather than by inventing one.
     */
    case ApprovedBy = 'approved_by';

    /**
     * The owner's note on a rejection, stored as it was given. It lives on the row and never in
     * the audit payload: a note explaining why a request was turned down may name the customer,
     * and the trail holds nothing to erase.
     */
    case RejectionReason = 'rejection_reason';

    /**
     * The provider's id of the payment that moved, written by the paid transition alone over
     * the session the pay hop recorded: the payment a later note names is the one the provider
     * said landed, which need not be the session recorded.
     */
    case PaymentRef = 'payment_ref';
}
