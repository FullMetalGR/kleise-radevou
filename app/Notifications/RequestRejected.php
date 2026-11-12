<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the customer is told when the human they were waiting on said no.
 *
 * **The owner's reason is not in it and cannot be put in it.** A note explaining a refusal is
 * written for the people who work there, it may name the customer and it may say things nobody
 * would say to their face; it lives on the booking row, where erasure reaches it, and it is
 * already withheld from the customer's own view of the booking. So the rule is kept by the
 * shape rather than by remembering it: a job re-reads a BookingSnapshot, the snapshot has no
 * such column, and this template takes nothing but the booking.
 *
 * **It names the booking by its times.** The customer asked for an hour, not for an identifier,
 * and a customer with two requests outstanding has to be able to tell which one this is about.
 * That is the whole reason the times are here; the slot is gone either way.
 *
 * It carries no calendar file, because there is no appointment, and no link, because the record
 * behind it carries no token and a rejected booking has nothing left to manage.
 *
 * Its words are customer-mail.php's rejected group, read in the booking's language, the one the
 * booking was made in, and so are the booking's facts; the paragraphs are whole lines and the
 * blank lines and the indented booking are this layout's.
 */
final readonly class RequestRejected
{
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line('customer-mail.rejected.declined', $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerMailLines::line('customer-mail.rejected.free_again', $language),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::Rejected,
            new Recipient($booking->person()->email),
            CustomerMailLines::line('customer-mail.rejected.subject', $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }
}
