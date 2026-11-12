<?php

declare(strict_types=1);

namespace App\Application\Notification;

/**
 * Every message this product sends, once each.
 *
 * It is the one list. Nothing sends a key that is not here, and the admin reads nothing else
 * to draw the Emails section of Settings: one checkbox per key, ticked while the key is absent
 * from the tenant's emails_off, because the stored value is the negative and a message added
 * in a later release has to send by default rather than silently not.
 *
 * The list is flat and every key is a distinct string. A key shared between the customer's
 * word for a transition and the business's would be one checkbox silencing two audiences, and
 * a tenant who stops telling their customers about cancellations has not asked to stop hearing
 * about them themselves.
 *
 * The label is what sits beside the checkbox. Its words are lang/{en,el}/admin/emails.php's
 * and the case answers its key there, derived from its own value, so adding a message is one
 * case here and one line in each language file and never a React change. The enum reads no
 * translator: the settings prop words each label in the request's language, and the automatic
 * approval's notice the approved one in the mail's.
 */
enum MessageKey: string
{
    /** The group of lang/{locale}/admin/emails.php holding one label per case, by its value. */
    public const string LABELS = 'admin/emails.labels';

    case Acknowledged = 'acknowledged';
    case Confirmed = 'confirmed';

    /**
     * The customer's yes. Its awaiting-payment form carries the payment link, so while the tenant
     * takes payments the key is held on whatever emails_off says, Settings::locked, and switchable
     * again once no connection works.
     */
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Moved = 'moved';
    case Reminder = 'reminder';
    case RequestExpired = 'request_expired';
    case NewBooking = 'new_booking';
    case ApprovalRequest = 'approval_request';
    case ApprovalNag = 'approval_nag';
    case AutoApproved = 'auto_approved';
    case CancellationNotice = 'cancellation_notice';
    case MoveNotice = 'move_notice';

    /**
     * A payment landed for a booking it did not confirm, so it bought nothing, and
     * refunding it is the owner's act in their provider's dashboard. It goes to the owners
     * alone, BusinessRecipients::owners, since what to do with money is theirs to decide.
     */
    case LatePayment = 'late_payment';

    /**
     * The one-time link a customer asks for on the hosted page, which opens My bookings, the
     * session over every booking of their address at this tenant. The customer's, and switchable
     * like every key: while it is off nothing mints a link, the header hides the way in, and no
     * customer message carries the line pointing at it. It is the one key no transition sends, so
     * its message names no booking.
     */
    case MyBookingsLink = 'my_bookings_link';

    /**
     * The customer thanked for their visit once the business marks the booking completed, with
     * the tenant's Google reviews link where the profile holds one and Book again. The
     * customer's, and switchable like every key.
     */
    case ThankYou = 'thank_you';

    public function audience(): Audience
    {
        return match ($this) {
            self::Acknowledged, self::Confirmed, self::Approved, self::Rejected,
            self::Cancelled, self::Moved, self::Reminder, self::RequestExpired, self::MyBookingsLink, self::ThankYou => Audience::Customer,
            self::NewBooking, self::ApprovalRequest, self::ApprovalNag,
            self::AutoApproved, self::CancellationNotice, self::MoveNotice, self::LatePayment => Audience::Business,
        };
    }

    /**
     * Whether this key goes to the owners alone rather than to the business recipients every
     * other business key reaches: the late payment, since what to do with money is theirs to
     * decide. The one home of that rule: BusinessRecipients::under sends by it and the Emails
     * section tags by it, "to the owners"; its switch stays under the catalog's rule.
     */
    public function ownersAlone(): bool
    {
        return $this === self::LatePayment;
    }

    /**
     * The key of the words the tenant reads beside the checkbox that switches this message off.
     * Each label names the event rather than the template, because that is what a tenant is
     * deciding about.
     */
    public function labelKey(): string
    {
        return self::LABELS.'.'.$this->value;
    }
}
