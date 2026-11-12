<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the customer is told when a hold they were waiting on lapsed: their request nobody
 * answered in time, or, in the second form below, the payment hold an approval left them.
 *
 * The first form is the third of the three answers and it is an answer, which is the whole of the
 * rule: an abandoned checkout lapsing is not news, because that customer walked away from a page
 * and knows it, but a timed-out approval request is somebody who was told to wait and would
 * otherwise wait for ever. The listener is where the hold class decides which of the three an
 * expiry was, checkout, approval or payment; this is only ever built for the two that are news.
 *
 * The first form says the time is free again rather than that the request was refused, because
 * nobody refused it. A request whose service auto-approves on timeout never reaches that form:
 * it was granted, and its customer reads RequestApproved. Granted onto a payment hold that then
 * lapses unpaid, it reaches the second.
 *
 * It carries no calendar file, because there is no appointment, and no link, because the record
 * behind it carries no token and an expired booking has nothing left to manage.
 *
 * **It has a second form, unpaid(), under the same key**: an approved booking whose payment hold
 * lapsed before the payment reached the business. Where the tenant has the approved message on,
 * that customer was told to pay and wait, by that message and its payment link, and would
 * otherwise not know the time went back; the approved message promised "the link says so", and
 * this says it first. Where it is off, this is the customer's first word of the approval. It says
 * the payment did not arrive rather than that nobody answered, because somebody did. No key is
 * added for it: a tenant that switched request_expired off keeps it off for both, and the label,
 * "Request timed out", is true of both.
 */
final readonly class RequestExpired
{
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, ?string $myBookings = null): Message
    {
        return self::form($booking, $tenantName, $clock, $myBookings, [
            'customer-mail.request_expired.timed_out.subject',
            'customer-mail.request_expired.timed_out.expired',
            'customer-mail.request_expired.timed_out.free_again',
        ]);
    }

    /**
     * The approved booking whose payment hold lapsed unpaid.
     */
    public static function unpaid(BookingSnapshot $booking, string $tenantName, TenantClock $clock, ?string $myBookings = null): Message
    {
        return self::form($booking, $tenantName, $clock, $myBookings, [
            'customer-mail.request_expired.unpaid.subject',
            'customer-mail.request_expired.unpaid.expired',
            'customer-mail.request_expired.unpaid.free_again',
        ]);
    }

    /**
     * Either form, which share their layout and differ in their words alone: the form's subject,
     * its opening and its closing sentence, each a whole line of customer-mail.php's
     * request_expired group, read in the booking's language, and so are the booking's facts.
     *
     * @param  array{string, string, string}  $keys  the form's subject, opening and closing sentence
     */
    private static function form(BookingSnapshot $booking, string $tenantName, TenantClock $clock, ?string $myBookings, array $keys): Message
    {
        [$subject, $expired, $freeAgain] = $keys;
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line($expired, $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerMailLines::line($freeAgain, $language),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::RequestExpired,
            new Recipient($booking->person()->email),
            CustomerMailLines::line($subject, $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }
}
