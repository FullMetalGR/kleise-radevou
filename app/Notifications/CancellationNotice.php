<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;

/**
 * What the business is told when a booking somebody there was expecting is given up.
 *
 * **It goes every time, whoever acted**, and says who. A customer cancelling through their link
 * is news to everyone at the business; a user cancelling from the admin is news to everyone
 * there but the one who clicked, and a tenant has several people, so the notice goes to all of
 * them and names the one who did it rather than answering nobody's click back to nobody. Who
 * it was comes from WhoActed, which is the one place an actor on a record is turned into a name.
 *
 * **It is under a key of its own**, cancellation_notice, because the customer's message about the
 * same act is under cancelled and one switch must never silence two audiences: an owner who stops
 * telling customers about cancellations has not asked to stop hearing about them themselves.
 *
 * **It carries no calendar file and no magic link**, for the reasons the new-booking notice
 * carries neither: the business's diary is the product, and the link is the customer's own
 * credential. How to reach the customer is manageBookings' to know, and the recipient carries
 * that answer.
 *
 * One recipient per message, because the recipient rule names several people and a refused
 * mailbox must silence nobody else.
 *
 * Its words are business-mail.php's cancellation_notice group, read in the recipient's language:
 * what was cancelled, a request or a booking, its own subject, opening and what follows from it,
 * and who cancelled it one whole sentence per shape WhoActed tells apart, so no sentence is
 * spliced from request or booking or from a phrase naming the actor.
 */
final readonly class CancellationNotice
{
    /**
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it was cancelled
     * @param  WhoActed  $cancelledBy  who cancelled it, its shape and its name
     */
    public static function of(BookingSnapshot $booking, string $tenantName, TenantClock $clock, Recipient $to, bool $wasARequest, WhoActed $cancelledBy): Message
    {
        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];
        [$subject, $opening, $means] = $wasARequest
            ? ['business-mail.cancellation_notice.request.subject', 'business-mail.cancellation_notice.request.opening', 'business-mail.cancellation_notice.request.means']
            : ['business-mail.cancellation_notice.booking.subject', 'business-mail.cancellation_notice.booking.opening', 'business-mail.cancellation_notice.booking.means'];

        $body = implode("\n\n", [
            BusinessMailLines::line($opening, $language, $business),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language),
            BookingLines::total($booking, $language),
            // Two whole sentences, who cancelled it and what follows, set side by side.
            BusinessMailLines::line(self::byWhom($cancelledBy), $language, $cancelledBy->names()).' '.BusinessMailLines::line($means, $language),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::CancellationNotice,
            $to,
            BusinessMailLines::line($subject, $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * The sentence saying who cancelled it, one key per shape of actor.
     */
    private static function byWhom(WhoActed $who): string
    {
        return match ($who->shape) {
            ActorShape::Customer => 'business-mail.cancellation_notice.by.customer',
            ActorShape::User => 'business-mail.cancellation_notice.by.user',
            ActorShape::RemovedUser => 'business-mail.cancellation_notice.by.removed',
            ActorShape::Support => 'business-mail.cancellation_notice.by.support',
            ActorShape::System => 'business-mail.cancellation_notice.by.system',
        };
    }
}
