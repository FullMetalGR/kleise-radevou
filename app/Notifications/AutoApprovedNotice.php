<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the business is told when the clock granted a request they never got round to.
 *
 * It is the one approval the business hears about, and it is under a key of its own for that
 * reason. Being told the outcome of one's own click is noise, so an owner who approved a request
 * is told nothing; a request granted because nobody answered it is a booking the business has
 * never seen, and the policy that granted it is one they set on the service rather than a decision
 * anybody made that day. An owner who finds even that noisy switches off auto_approved alone, and
 * goes on receiving the requests themselves.
 *
 * **It carries no calendar file and no magic link**, for the reasons the new-booking notice
 * carries neither: the business's diary is the product, and the link is the customer's credential
 * for their own booking.
 *
 * **Whether the customer heard is read and not assumed.** emails_off may carry any key, the
 * customer's approval among them, so "the customer has been told" is false for a tenant who
 * switched that one off. Where it is off the notice says so and names the switch by the words
 * beside its checkbox and by whose settings it sits in, the tenant's, because a business reading
 * that nobody has told their customer is a business that may want to pick up the telephone, and
 * the one thing that would let them stop reading it is the setting this sentence names. Not "your
 * settings": the recipient rule mails a staff row's own address too, and a person who never signs
 * in has no settings screen.
 *
 * **How to reach the customer is manageBookings' to know**, on a message as on every other
 * surface. The recipient carries the answer, the role it was read from is BusinessRecipients', and
 * the block is CustomerFacts'.
 *
 * **Where the grant landed is read from the record and not assumed**. A grant of an online request
 * under a working payment connection lands awaiting_payment, and that booking is not confirmed: it
 * is held for the customer's payment, and is given back if the payment does not arrive. The notice
 * says so under the same key; where the customer was not told, they hold no link to pay with, and
 * the business is told that too. The approved key is held on while a connection works
 * (Settings::locked), so that form is left for a connection that stopped working between the grant
 * and the send.
 *
 * One recipient per message, because the recipient rule names several people and a refused
 * mailbox must silence nobody else.
 *
 * Its words are business-mail.php's auto_approved group, read in the recipient's language, each of
 * the four forms one whole paragraph; the switch is named by its label as :label, the words beside
 * its checkbox, the line of lang/{locale}/admin/emails.php MessageKey names, read in the same
 * language as the paragraph, so a changed label never needs a copy edit.
 */
final readonly class AutoApprovedNotice
{
    /**
     * @param  bool  $customerWasTold  whether this tenant's approval message to the customer is switched on
     * @param  bool  $awaitingPayment  whether the grant landed awaiting_payment rather than confirmed
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, Recipient $to, bool $customerWasTold, bool $awaitingPayment = false): Message
    {
        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];

        $body = implode("\n\n", [
            BusinessMailLines::line('business-mail.auto_approved.opening', $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language),
            BookingLines::total($booking, $language),
            BusinessMailLines::line(self::whatHappened($customerWasTold, $awaitingPayment), $language, [
                'label' => BusinessMailLines::line(MessageKey::Approved->labelKey(), $language),
                'business' => $tenantName,
            ]),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::AutoApproved,
            $to,
            BusinessMailLines::line('business-mail.auto_approved.subject', $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * What the clock did, and whether the customer knows it, in the one of its four forms that is
     * true of this booking: where the grant landed, confirmed or awaiting the customer's payment,
     * and whether the customer's approval message is switched on. The key of the form's paragraph.
     */
    private static function whatHappened(bool $customerWasTold, bool $awaitingPayment): string
    {
        return match (true) {
            $awaitingPayment && $customerWasTold => 'business-mail.auto_approved.awaiting_payment.told',
            $awaitingPayment => 'business-mail.auto_approved.awaiting_payment.untold',
            $customerWasTold => 'business-mail.auto_approved.confirmed.told',
            default => 'business-mail.auto_approved.confirmed.untold',
        };
    }
}
