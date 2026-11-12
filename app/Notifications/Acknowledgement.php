<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What a customer is told when they have asked for something a human has to say yes to.
 *
 * It exists because an approval hold is a customer waiting on a person, and the approval
 * request goes to the approver, so without this they hear nothing at all until somebody
 * decides. A checkout hold gets no such message, because that customer is still in the booking
 * flow and mailing there would mail every abandoned checkout.
 *
 * It carries the magic link, and that is the part to be careful with. The manage token is
 * delivered in the hold response and in this link and nowhere else; the approval that may
 * follow carries no token, and only the hash is stored. So for a customer whose request is
 * approved a day later, this message is the only durable copy of their credential they will
 * ever have.
 *
 * The times are the tenant's, named once. The chain is named by its services in order and
 * timed from its first segment, because a customer thinks of the whole of it as one
 * appointment and the gaps in between are the salon's arrangement rather than theirs.
 *
 * Its words are customer-mail.php's acknowledged group, read in the booking's language, the one
 * the booking was made in, and so is the instant, ICU's in that language; the paragraphs are
 * whole lines and the blank lines and the indented instant and link are this layout's.
 */
final readonly class Acknowledgement
{
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, string $link, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];
        // When the booking begins is the snapshot's answer, startsAt: a booking is a container of
        // at least one item, and BookingSnapshot refuses an empty list, so an itemless booking is
        // unrepresentable here rather than unhandled.
        $when = TenantTime::instant($booking->startsAt(), $clock, $language);

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line('customer-mail.acknowledged.received', $language, ['service' => CustomerMailLines::services($booking), ...$business]),
            '    '.$when,
            CustomerMailLines::line('customer-mail.acknowledged.deciding', $language, $business),
            CustomerMailLines::line('customer-mail.acknowledged.manage', $language),
            '    '.$link,
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::Acknowledged,
            new Recipient($booking->person()->email),
            CustomerMailLines::line('customer-mail.acknowledged.subject', $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }
}
