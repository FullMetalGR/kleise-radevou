<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the customer is reminded of, the tenant's lead before their appointment: the items with
 * their times, the total, the way back to the booking, and Book again.
 *
 * **It is the one message that is scheduled rather than triggered.** Every other template
 * announces a transition; this one announces nothing, because nothing happened. It is built from
 * the booking as it stands when the job fires, after the re-read that decides whether it fires at
 * all, SendReminder, so the times it prints are the times the diary holds at that moment and never
 * the ones the reminder was queued for.
 *
 * **The way back is a reference and not a link.** The plaintext manage token is minted in two
 * moments, and a reminder is neither, so there is no credential to build a link from and the
 * message points at the one the customer already holds. Which earlier message carried it depends
 * on how the booking was settled: the confirmation, where the customer settled their own hold with
 * the token in hand; the acknowledgement, where an owner approved a request; and none at all for
 * the admin's booking, which has no token, or for a tenant who switched that earlier message off.
 * A sentence that named one of them would be false of the others, so this one is worded to be true
 * whichever it was and whether or not it went, and says who to ask where none did.
 *
 * **Book again is a link, and no credential.** It is the hosted page with the booking's first item
 * preselected, BookAgainLine, which asks nobody who they are and names no booking anybody could
 * act on, so it closes the message, before the line to My bookings, whatever the way back was.
 *
 * The staff member is named where the row is not the tenant's implicit one, ItemName, and
 * every segment of a chain is timed, BookingLines. The times are the tenant's, named once at
 * the foot. No file: the calendar entry was sent when the booking was confirmed and re-sent
 * when it moved, and a reminder changes nothing about it.
 *
 * Its words are customer-mail.php's reminder group, read in the booking's language, the one the
 * booking was made in, whatever the tenant's default has become by the time it fires, and so are
 * the booking's facts, the clock's line, Book again and the line to My bookings; the
 * earlier-message paragraph is one whole key.
 */
final readonly class Reminder
{
    /**
     * @param  string  $bookAgain  the hosted page preselecting the booking's first item, BookAgainLine
     * @param  string|null  $myBookings  the tenant's My bookings page for the closing line, null while the bookings link is off
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, string $bookAgain, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line('customer-mail.reminder.reminder', $language, $business),
            BookingLines::of($booking, $clock, $language),
            BookingLines::total($booking, $language),
            CustomerMailLines::line('customer-mail.reminder.earlier', $language, $business),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::Reminder,
            new Recipient($booking->person()->email),
            CustomerMailLines::line('customer-mail.reminder.subject', $language, $business),
            $body.BookAgainLine::line($bookAgain, $language).MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }
}
