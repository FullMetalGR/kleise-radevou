<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingItemSnapshot;
use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;

/**
 * What the customer is sent once the business marks their booking completed: thanks for the visit,
 * naming the business and what they came for, the tenant's Google reviews link under "Leave us a
 * review" where the profile holds one, Book again, and the closing line to My bookings while that
 * key is on.
 *
 * **It is the one customer message about a booking that is over**, so it carries no time, no total
 * and no calendar file: nothing about the appointment is left to keep or to change. What each item
 * was is ItemName's rule, the staff member named where the row is not the implicit one, as every
 * other template names them.
 *
 * **The reviews line is there exactly where the owner set the link**, and absent rather than empty
 * where not: a heading over nothing would ask the customer for a review they have no way to leave.
 * The link is the owner's own text off the settings row, read at the send, and the template neither
 * builds nor checks it.
 *
 * **It carries no credential.** Book again is the hosted page with a preselection, which asks
 * nobody who they are, and the reviews link is the business's page elsewhere; neither names the
 * booking, so the job that sends this is queued in the clear.
 *
 * Its words are customer-mail.php's thank_you group, read in the booking's language, the one the
 * booking was made in, and so are the items, Book again and the line to My bookings. The constants
 * that held its sentences hold their keys and keep their names.
 */
final readonly class ThankYou
{
    /** The subject, :business the business visited. */
    public const string SUBJECT = 'customer-mail.thank_you.subject';

    /**
     * The body's own opening after the greeting, the thanks naming the business, :business, before
     * the items; the closing wish after them is named in place.
     */
    public const string BODY = 'customer-mail.thank_you.thanks';

    /** The line to the tenant's reviews, :link the link the owner set. */
    public const string REVIEW_LINE = 'customer-mail.thank_you.review';

    /**
     * @param  string|null  $reviews  the tenant's Google reviews link off its profile, null where the owner set none
     * @param  string  $bookAgain  the hosted page preselecting the booking's first item, BookAgainLine
     * @param  string|null  $myBookings  the tenant's My bookings page for the closing line, null while the bookings link is off
     */
    public static function of(BookingSnapshot $booking, string $tenantName, ?string $reviews, string $bookAgain, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line(self::BODY, $language, $business),
            self::items($booking, $language),
            CustomerMailLines::line('customer-mail.thank_you.again', $language),
        ]);

        if ($reviews !== null) {
            $body .= "\n\n".CustomerMailLines::line(self::REVIEW_LINE, $language, ['link' => $reviews]);
        }

        return new Message(
            MessageKey::ThankYou,
            new Recipient($booking->person()->email),
            CustomerMailLines::line(self::SUBJECT, $language, $business),
            $body.BookAgainLine::line($bookAgain, $language).MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * What the customer came for, an item to a line in chain order, indented as BookingLines
     * indents them, each named by ItemName in the message's language.
     */
    private static function items(BookingSnapshot $booking, SiteLanguage $language): string
    {
        return implode("\n", array_map(fn (BookingItemSnapshot $item): string => '    '.ItemName::of($item, $language), $booking->items));
    }
}
