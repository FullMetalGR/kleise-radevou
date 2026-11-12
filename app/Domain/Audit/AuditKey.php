<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * The whole vocabulary of an audit payload. A payload key is one of these and nothing else,
 * which is what keeps a caller from copying a customer into the trail on the day somebody
 * wants one more detail in a dialog.
 *
 * Nothing here names a person. The trail records what changed and references the booking, so
 * erasure has nothing to reach into. The booking row is where a later screen reads the
 * customer from.
 */
enum AuditKey: string
{
    case StatusFrom = 'status_from';

    case StatusTo = 'status_to';

    case HoldClass = 'hold_class';

    case HoldExpiresAt = 'hold_expires_at';

    case ItemCount = 'item_count';

    /** The first segment's start, for a trail that explains when the booking was for. */
    case StartsAt = 'starts_at';

    /** For a move: the start it left. The start it moved to is StartsAt. */
    case MovedFrom = 'moved_from';

    /**
     * For the paid transition: whether the notice that confirmed the booking named its payment.
     * Where it did not, payment_ref keeps the session the hop recorded, or nothing, and no row
     * says which payment confirmed the booking.
     */
    case PaymentNamed = 'payment_named';

    /**
     * For the paid transition: the amount its notice says was paid, in the minor units of
     * PaidCurrency, where the notice carried one. Recorded and compared with nothing, so a
     * payment that reached a session before a move's expiry did says what it paid. A sum of
     * money, never who paid it.
     */
    case PaidMinor = 'paid_minor';

    /** For the paid transition: the three-letter currency of PaidMinor. */
    case PaidCurrency = 'paid_currency';

    /**
     * For the move, and only where it cleared the session a pay hop had recorded: true. The
     * pay hop counts these rows to know that the hold's end is already a session's, lengthened
     * past its own term by the provider's floor once, so the next session ends with the hold
     * as it stands and the count keys it apart from every session a move expired. A fact about
     * the booking, never a reference.
     */
    case SessionCleared = 'session_cleared';

    /**
     * For a move by the business, where it gave segments to other members: one fact per such
     * segment, its position in the chain as segment and the staff ids it left and took as from
     * and to. Ids and never names, for the reason nothing here names a person; the trail's
     * reader turns them into names.
     */
    case StaffChanged = 'staff_changed';

    /**
     * For a move by the business, where its accepted warning was needed: one fact per segment and
     * kind, the segment's position and what it crossed, hours or closure (Overridable).
     */
    case Overridden = 'overridden';

    /** For a move by the business: whether the customer was sent the moved message. */
    case CustomerTold = 'customer_told';
}
