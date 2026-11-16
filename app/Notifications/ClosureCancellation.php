<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the customer is told when the business cancels their booking, or rejects their request,
 * because it added a closure over the booking's time: in place of the usual cancellation or
 * rejection, never beside it, and under the same key, so the tenant's switch for that message
 * decides whether this one goes too.
 *
 * It says the business had to cancel, names the booking's day, time and services as every
 * booking message does, gives the closure's reason when the business typed one, and ends on
 * Book another time, the hosted booking page in the booking's language with the booking's first
 * service already chosen, the same link Book again opens.
 *
 * Its words are customer-mail.php's closure group, read in the booking's language: a booking and
 * a request each their own subject, opening and closing paragraph, whole keys, so no sentence is
 * spliced from the two.
 */
final readonly class ClosureCancellation
{
    /**
     * @param  MessageKey  $key  the key of the message this one replaces, Cancelled or Rejected
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner
     * @param  string  $bookAnother  the hosted booking page, as BookAgainLine builds it for this booking
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, MessageKey $key, bool $wasARequest, ?string $reason, string $bookAnother, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];
        $form = $wasARequest ? 'customer-mail.closure.request' : 'customer-mail.closure.booking';

        $paragraphs = [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line($form.'.cancelled', $language, $business),
            BookingLines::of($booking, $clock, $language),
        ];

        if ($reason !== null) {
            $paragraphs[] = CustomerMailLines::line('customer-mail.closure.reason', $language, ['reason' => $reason]);
        }

        $paragraphs[] = CustomerMailLines::line($form.'.after', $language);
        $paragraphs[] = TenantTime::footer($clock->zone, $tenantName, $language);
        $paragraphs[] = CustomerMailLines::line('customer-mail.closure.book', $language, ['link' => $bookAnother]);

        return new Message(
            $key,
            new Recipient($booking->person()->email),
            CustomerMailLines::line($form.'.subject', $language, $business),
            implode("\n\n", $paragraphs).MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }
}
