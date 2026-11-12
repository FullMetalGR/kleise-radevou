<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What an owner is told when a payment lands for a booking it did not confirm: which booking,
 * that the payment bought nothing, and that refunding it is theirs to decide in their payment
 * provider's dashboard.
 *
 * It names the booking by its lines, its customer as the owner may see them, its total and its
 * reference, the identifier the provider's records and any note left on the payment carry. It
 * carries no secret and no link: nothing here is an act, and the refund is not ours to make.
 * It does not say why the payment was late or whether a note was left on it, since the road
 * that queued it does not carry either to the job and the owner acts the same way whatever the
 * answer.
 *
 * Its words are business-mail.php's late_payment group, read in the owner's language, one
 * message per owner, so two owners of one tenant may read two languages; the reference is its
 * label and the booking's uuid, as the customer's facts are.
 */
final readonly class LatePaymentNotice
{
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, Recipient $to): Message
    {
        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            BusinessMailLines::line('business-mail.late_payment.arrived', $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language),
            BookingLines::total($booking, $language),
            BusinessMailLines::line('business-mail.late_payment.reference', $language).': '.$booking->uuid,
            BusinessMailLines::line('business-mail.late_payment.stands', $language),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::LatePayment,
            $to,
            BusinessMailLines::line('business-mail.late_payment.subject', $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }
}
