<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the customer is told when a booking of theirs is given up: their own cancellation
 * confirmed back to them, or the business's told to them.
 *
 * **It is worded by who acted and by what was cancelled**, and both are the record's to say,
 * not the row's. The row reads cancelled either way, and the class that said which hold it was
 * on is nulled with it; the record carries the status it came from and the actor, and those two
 * decide four sets of words. A customer who pressed Cancel is told what they did; a customer
 * whose booking the business cancelled is told that, and told who to ask, because the one thing
 * they did not do is expect it. A request nobody had yet answered is withdrawn, not cancelled:
 * no appointment ever existed, and saying one was cancelled would be false of the case.
 *
 * **It carries no calendar file and no link.** There is no appointment left to keep in a diary
 * and nothing left to manage; the record carries no token, and a cancelled booking has nothing a
 * link could do.
 *
 * A checkout hold never reaches this template at all. The rule is that a checkout hold gets no
 * mail on any event, and the listener is where the hold class can still be read.
 *
 * Its words are customer-mail.php's cancelled group, read in the booking's language, the one the
 * booking was made in, and so are the booking's facts and the clock's line: each of the four
 * forms its own subject, its own opening and its own closing paragraph, whole keys under what
 * was cancelled and who acted, so no sentence is spliced from request or booking, withdrawn or
 * cancelled.
 */
final readonly class Cancellation
{
    /**
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it was cancelled
     * @param  bool  $byTheCustomer  whether the customer cancelled it themselves
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, bool $wasARequest, bool $byTheCustomer, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];
        [$subject, $cancelled, $means] = self::form($wasARequest, $byTheCustomer);

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line($cancelled, $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerMailLines::line($means, $language, $business),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::Cancelled,
            new Recipient($booking->person()->email),
            CustomerMailLines::line($subject, $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * The form whose words are true of this booking, by what was cancelled, a request or a
     * booking, and by who did it: its subject, the sentence saying what was done, and what follows
     * from it, the three keys of customer-mail.php's cancelled group that form holds.
     *
     * @return array{string, string, string}
     */
    private static function form(bool $wasARequest, bool $byTheCustomer): array
    {
        return match (true) {
            $wasARequest && $byTheCustomer => [
                'customer-mail.cancelled.request.by_customer.subject',
                'customer-mail.cancelled.request.by_customer.cancelled',
                'customer-mail.cancelled.request.by_customer.means',
            ],
            $wasARequest => [
                'customer-mail.cancelled.request.by_business.subject',
                'customer-mail.cancelled.request.by_business.cancelled',
                'customer-mail.cancelled.request.by_business.means',
            ],
            $byTheCustomer => [
                'customer-mail.cancelled.booking.by_customer.subject',
                'customer-mail.cancelled.booking.by_customer.cancelled',
                'customer-mail.cancelled.booking.by_customer.means',
            ],
            default => [
                'customer-mail.cancelled.booking.by_business.subject',
                'customer-mail.cancelled.booking.by_business.cancelled',
                'customer-mail.cancelled.booking.by_business.means',
            ],
        };
    }
}
