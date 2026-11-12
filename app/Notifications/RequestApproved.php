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
 * What the customer is told when the human they were waiting on said yes.
 *
 * It is not the confirmation and must not be folded into it. A confirmation follows a customer
 * settling their own hold, and this follows somebody else deciding: the customer heard nothing
 * between the acknowledgement and this, so this message carries the answer as well as the
 * booking. They part further still for an approval that has to be paid for, which lands on
 * awaiting_payment, where there is a decision to announce and no confirmed booking to announce it
 * about: awaitingPayment below says so, with the payment link and no calendar file.
 *
 * **The confirmed form names the acknowledgement as where the manage link is, and carries no
 * manage link itself.** The plaintext manage token is minted in two moments only, and the record
 * this is built from is neither: an approval is somebody else's act on somebody else's screen and
 * there is no manage token anywhere near it. The awaiting-payment form does carry a credential,
 * of another kind: the signed payment link, minted by the job from the booking alone. The
 * acknowledgement is the customer's one durable copy: the booking app holds the token in memory,
 * never renders it and loses it on a reload, so the mail is the only copy that outlives the tab.
 *
 * **Which is why it is told whether that message went.** Every approval hold is acknowledged
 * whatever the re-read found, but emails_off may carry any key including that one, and for a
 * tenant who switched it off the customer holds no link at all and has never been written to.
 * Pointing them at a message nobody sent would be a sentence false of the case it is printed for,
 * so that customer is sent to the business instead. The switch is read where the switches live,
 * the seam, and read now: a tenant who flipped it between the hold and the decision is the one
 * case no read can see, because nothing records per booking which messages went.
 *
 * **The confirmed form carries the calendar file at revision zero.** A booking reaching confirmed
 * through an approval publishes BookingApproved and not BookingConfirmed, so no confirmation has
 * sent a file for this uuid and no calendar anywhere has imported it; this is the first. A move
 * re-sends the same entry at the next number. The awaiting-payment form carries no file at all.
 *
 * The times are the tenant's, named once at the foot. The calendar file beside them is UTC,
 * because its reader is a machine that will convert; see IcsBuilder.
 *
 * Its words are customer-mail.php's approved group, a group per form, read in the booking's
 * language, the one the booking was made in, and so are the booking's facts and the calendar
 * file's summary; the paragraphs are whole lines, a branch of where the customer's link is each
 * its own, and the blank lines, the indented booking and the payment link are this layout's.
 */
final readonly class RequestApproved
{
    /**
     * @param  DateTimeImmutable  $stampedAt  when this is being sent, which is what the calendar file stamps itself with
     * @param  bool  $acknowledgementWasSent  whether this tenant's acknowledgement is switched on, which is whether the customer holds a link
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, DateTimeImmutable $stampedAt, bool $acknowledgementWasSent, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line('customer-mail.approved.confirmed.approved', $language, $business),
            BookingLines::of($booking, $clock, $language),
            BookingLines::total($booking, $language),
            self::whereTheirLinkIs($tenantName, $acknowledgementWasSent, $language),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::Approved,
            new Recipient($booking->person()->email),
            CustomerMailLines::line('customer-mail.approved.confirmed.subject', $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            [IcsBuilder::attachment($booking, CustomerMailLines::summary($booking, $tenantName, $language), $stampedAt, 0)],
            $language->value,
        );
    }

    /**
     * The same answer for an approval that landed awaiting_payment: the request was approved and
     * the booking is completed by paying, through the signed link this carries. Under the same
     * key, so a tenant's switch keeps its meaning.
     *
     * It carries no calendar file, because there is no confirmed booking to put in a calendar;
     * the confirmation that follows the payment carries the first, numbered by the reader. It
     * carries the link whether or not the acknowledgement went, since the link is not the manage
     * link and is minted here, by the job, from the booking alone. It quotes no deadline: the
     * hold may end with the provider's session rather than the tenant's setting, and the link's
     * own page says, every time it is opened, whether the time is still held.
     */
    public static function awaitingPayment(BookingSnapshot $booking, string $tenantName, TenantClock $clock, string $payLink, ?string $myBookings = null): Message
    {
        $language = Languages::ofBooking($booking->language);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.greeting', $language, ['customer' => $booking->person()->name]),
            CustomerMailLines::line('customer-mail.approved.awaiting_payment.approved', $language, $business),
            BookingLines::of($booking, $clock, $language),
            BookingLines::total($booking, $language),
            CustomerMailLines::line('customer-mail.approved.awaiting_payment.pay', $language),
            $payLink,
            CustomerMailLines::line('customer-mail.approved.awaiting_payment.held', $language),
            TenantTime::footer($clock->zone, $tenantName, $language),
        ]);

        return new Message(
            MessageKey::Approved,
            new Recipient($booking->person()->email),
            CustomerMailLines::line('customer-mail.approved.awaiting_payment.subject', $language, $business),
            $body.MyBookingsLine::closing($myBookings, $language),
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * The paragraph about the file and about getting back to the booking, in the one of its two
     * forms that is true of this customer, each one whole line.
     */
    private static function whereTheirLinkIs(string $tenantName, bool $acknowledgementWasSent, SiteLanguage $language): string
    {
        return $acknowledgementWasSent
            ? CustomerMailLines::line('customer-mail.approved.confirmed.where_link.acknowledged', $language)
            : CustomerMailLines::line('customer-mail.approved.confirmed.where_link.unacknowledged', $language, ['business' => $tenantName]);
    }
}
