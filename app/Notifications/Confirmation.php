<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use DateTimeImmutable;

/**
 * What the customer is told when the slot is theirs: the appointments, the total, the way back
 * to the booking, the file for their own diary, and Book again.
 *
 * **It is the one message that goes out however the booking was settled.** A customer who paid
 * nothing and pressed Confirm, a customer whose request an owner approved, an admin entering a
 * booking over the phone: each of them ends with a confirmed booking, and each customer is owed
 * the same account of it. What differs is who else hears about it, which is the listener's rule
 * and not this template's.
 *
 * **The link is optional, and its absence is not a broken message.** The plaintext manage token is
 * minted in two moments only, and a confirmation reached without one - the admin's, a job's - has
 * no link to give. Saying so by leaving the paragraph out is the honest answer; printing a link
 * with no credential in it would send the customer to a page that asks the server nothing.
 *
 * **The total is the booking row's own**, in the currency that row carries and not the tenant's
 * current default: changing the setting must never restate a price somebody has already agreed to.
 *
 * **The tenant's Important info follows the booking where the owner set it**, under the heading
 * the review step shows it under, and before the way back to the booking: the one text the
 * business asked every customer to read. Where it is unset there is no heading over nothing.
 *
 * **Book again closes the message, before the line to My bookings**: the hosted page with the
 * booking's first item preselected, BookAgainLine, which the job builds for every form and hands
 * over whatever the tenant has switched off, since it points at the booking page and carries no
 * credential.
 *
 * The times are the tenant's, named once at the foot. The calendar file beside them is UTC,
 * because its reader is a machine that will convert; see IcsBuilder.
 *
 * Its words are customer-mail.php's confirmed group, read in the booking's language, the one the
 * booking was made in, and so are the booking's facts, the calendar file's summary and
 * description, Book again and the line to My bookings; the paragraphs are whole lines, a branch of
 * where the customer's link is each its own, and the blank lines and the indented booking and link
 * are this layout's. The heading over the Important info is the review step's own key of
 * booking.php, one heading for the page and the mail, read in the booking's language; the
 * Important info under it is the owner's text as typed, never translated.
 */
final readonly class Confirmation
{
    /**
     * The heading over the tenant's Important info, the review step's own heading for it too: its
     * key of booking.php, the one the review step reads (ReviewStep.tsx's IMPORTANT_INFO_HEADING).
     */
    public const string IMPORTANT_INFO_HEADING = 'booking.review.important_info';

    /**
     * @param  string|null  $link  the magic link, where the confirming request carried the token to build one
     * @param  DateTimeImmutable  $stampedAt  when this is being sent, which is what the calendar file stamps itself with
     * @param  string  $bookAgain  the hosted page preselecting the booking's first item, BookAgainLine
     * @param  int  $revision  the calendar file's number: zero for the first file for this uuid, the reader's after a payment
     * @param  bool|null  $acknowledgementWasSent  with no link, whether the customer holds one from the acknowledgement of their request; null says nothing about a link, as a confirmation reached without a token always has
     * @param  string|null  $importantInfo  the tenant's Important info off its settings row, null where the owner set none
     * @param  string|null  $myBookings  the tenant's My bookings page for the closing line, null while the bookings link is off
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, ?string $link, DateTimeImmutable $stampedAt, string $bookAgain, int $revision = 0, ?bool $acknowledgementWasSent = null, ?string $importantInfo = null, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $paragraphs = [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line('customer-mail.confirmed.confirmed', $language, $business),
            BookingLines::of($booking, $clock, $language),
            BookingLines::total($booking, $language),
            CustomerMailLines::line('customer-mail.confirmed.calendar', $language),
        ];

        if ($importantInfo !== null) {
            $paragraphs[] = CustomerMailLines::line(self::IMPORTANT_INFO_HEADING, $language)."\n".$importantInfo;
        }

        if ($link !== null) {
            $paragraphs[] = CustomerMailLines::line('customer-mail.confirmed.manage', $language);
            $paragraphs[] = '    '.$link;
        } elseif ($acknowledgementWasSent !== null) {
            $paragraphs[] = self::whereTheirLinkIs($tenantName, $acknowledgementWasSent, $language);
        }

        $paragraphs[] = TenantTime::footer($clock->zone, $tenantName, $language);

        return new Message(
            MessageKey::Confirmed,
            new Recipient($booking->person()->email),
            CustomerMailLines::line('customer-mail.confirmed.subject', $language, $business),
            implode("\n\n", $paragraphs).BookAgainLine::line($bookAgain, $language).MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            // Zero is the first file for this booking, which is what the revision counts from: a
            // booking becomes confirmed once, and a confirmation reached by a confirm or an admin's
            // create is sent on that transition, so no earlier file has published this uuid. The
            // one after a payment asks the reader instead, because an approval may already have
            // sent one at zero.
            [IcsBuilder::attachment($booking, CustomerMailLines::summary($booking, $tenantName, $language), $stampedAt, $revision)],
            $language->value,
        );
    }

    /**
     * Where a customer confirmed by their payment, with no link to hand, finds their booking: in
     * the acknowledgement of their request where one was sent, and otherwise with the business,
     * as the approved message says it. One whole line per branch.
     */
    private static function whereTheirLinkIs(string $tenantName, bool $acknowledgementWasSent, SiteLanguage $language): string
    {
        return $acknowledgementWasSent
            ? CustomerMailLines::line('customer-mail.confirmed.where_link.acknowledged', $language)
            : CustomerMailLines::line('customer-mail.confirmed.where_link.unacknowledged', $language, ['business' => $tenantName]);
    }
}
