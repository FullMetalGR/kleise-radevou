<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the business is told when a customer has booked and settled it themselves and, in its
 * paid form, when a payment has confirmed a booking they approved.
 *
 * It exists for the two cases where the business learns something it did not decide: a customer
 * came to the page, took a slot and confirmed it, nobody at the business having been asked
 * anything; and a payment landed on a booking the owner approved, the paid form below. A booking
 * the owner approved is otherwise a booking the owner already decided, and telling them about it
 * again would be answering their own click; the case where the clock decided for them is told
 * under its own key.
 *
 * **It carries no magic link.** The link is the customer's credential for their own booking, and
 * the business reaches the same booking through the admin as themselves. A link here would copy a
 * customer's credential into every owner's and manager's mailbox and into whatever their mail
 * rules forward it on to.
 *
 * **It carries no calendar file either.** The file is a customer's diary entry; the business's
 * diary is the product, and a tenant importing their own bookings into a second calendar would
 * be keeping the same appointments in two places with nothing reconciling them.
 *
 * **How to reach the customer is manageBookings' to know.** A message is a surface like any
 * other, and the recipient rule names people who do not hold that permission: a staff user, who
 * hears about the bookings on their own row, and a staff row's own mailbox, which is nobody at
 * all. They are told whose booking it is, because a name is how a person recognises their own
 * morning, and they reach that person through whoever runs the diary. The recipient carries the
 * answer; the role it was read from is BusinessRecipients', and the block itself is
 * CustomerFacts', which is where the three surfaces asking that question agree on it.
 *
 * One recipient per message, because the recipient rule names several people and a message is
 * built for one of them, so a refused mailbox silences nobody else and a link can be signed per
 * user.
 *
 * The customer's note follows who they are, under its heading, where they wrote one,
 * CustomerNote.
 *
 * Its words are business-mail.php's new_booking group, read in the recipient's language, the
 * language the Recipient carries, and so are the booking's facts and the clock's line; the
 * constants that held its sentences hold their keys, names kept.
 *
 * **Its paid form**: an approved booking whose payment has just confirmed it. The business
 * decided the request, so the booking is not news, but its payment is: until it landed the time
 * was only held, and nothing else tells them it is now firm. It is the same key, the business's
 * word for a booking that is now confirmed, and no new one; it opens and is titled on the payment
 * and carries the rest as the checkout form does. A settlement on site at the pay hop moved no
 * money and is not announced, AnnounceNewBooking.
 */
final readonly class NewBookingNotice
{
    /** The checkout form's subject and first line, each with the business's name. */
    public const string SUBJECT = 'business-mail.new_booking.checkout.subject';

    public const string OPENING = 'business-mail.new_booking.checkout.opening';

    /** The paid form's, for an approved booking its payment confirmed. */
    public const string PAID_SUBJECT = 'business-mail.new_booking.paid.subject';

    public const string PAID_OPENING = 'business-mail.new_booking.paid.opening';

    /**
     * @param  string|null  $note  the customer's note, read by the job through BookingNotes, null where none
     * @param  bool  $paid  the paid form: an approved booking whose payment confirmed it
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, Recipient $to, ?string $note, bool $paid = false): Message
    {
        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            BusinessMailLines::line($paid ? self::PAID_OPENING : self::OPENING, $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language).CustomerNote::after($note, $language),
            BookingLines::total($booking, $language),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::NewBooking,
            $to,
            BusinessMailLines::line($paid ? self::PAID_SUBJECT : self::SUBJECT, $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }
}
