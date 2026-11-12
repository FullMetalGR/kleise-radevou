<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Tenancy\Languages;
use DateTimeImmutable;

/**
 * What the business is told when a booking somebody there was expecting is moved: the start it
 * left, the start it has now, and who moved it.
 *
 * **The two instants are the same two the customer reads**, from the same places: the start it
 * left is the record's, since the old items are gone, and the start it has is the row's as it
 * stands when the message goes, so the headline and the items beneath it cannot disagree.
 *
 * **It goes every time and says who moved it**, WhoActed's answer, for the reason the
 * cancellation notice does: a tenant has several people and the one who was expecting the
 * customer at the old time is not necessarily the one who was told. The business moves bookings
 * from the admin as well, and the words follow the record: a user by name, support by the public
 * name, the customer as the customer.
 *
 * **A moved request is still a request.** The move leaves the hold, its expiry and the decision
 * where they were, and no second approval request is sent: the links the first one carried are
 * signed over the booking and the user, not the time, and nothing is re-sent. The notice says a
 * request moved and nothing about what has since been decided, because that is a different fact
 * and one this message did not announce.
 *
 * **It carries no calendar file and no magic link**, for the reasons every business message
 * carries neither. How to reach the customer is manageBookings' to know, and the recipient
 * carries that answer. One recipient per message, because a refused mailbox must silence nobody
 * else.
 *
 * Its words are business-mail.php's move_notice group, read in the recipient's language: what
 * moved, a request or a booking, its own subject and opening with the two starts, each ICU's
 * instant in the same language, and who moved it one whole sentence per shape WhoActed tells
 * apart.
 */
final readonly class MoveNotice
{
    /**
     * @param  DateTimeImmutable  $movedFrom  the start the booking left, off the record
     * @param  bool  $wasARequest  whether the booking was still a request in front of the owner when it moved
     * @param  WhoActed  $movedBy  who moved it, its shape and its name
     */
    public static function of(
        BookingSnapshot $booking,
        string $tenantName,
        TenantClock $clock,
        Recipient $to,
        DateTimeImmutable $movedFrom,
        bool $wasARequest,
        WhoActed $movedBy,
    ): Message {
        $language = Languages::ofRecipient($to);
        $business = ['business' => $tenantName];
        [$subject, $opening] = $wasARequest
            ? ['business-mail.move_notice.request.subject', 'business-mail.move_notice.request.opening']
            : ['business-mail.move_notice.booking.subject', 'business-mail.move_notice.booking.opening'];

        $body = implode("\n\n", [
            BusinessMailLines::line($opening, $language, [
                ...$business,
                'from' => TenantTime::instant($movedFrom, $clock, $language),
                'to' => TenantTime::instant($booking->startsAt(), $clock, $language),
            ]),
            BookingLines::of($booking, $clock, $language),
            CustomerFacts::lines($booking->person(), $to->managesBookings, $language),
            BookingLines::total($booking, $language),
            BusinessMailLines::line(self::byWhom($movedBy), $language, $movedBy->names()),
            TenantTime::footerForTheBusiness($clock->zone, $language),
        ]);

        return new Message(
            MessageKey::MoveNotice,
            $to,
            BusinessMailLines::line($subject, $language, $business),
            $body,
            $booking->uuid,
            language: $language->value,
        );
    }

    /**
     * The sentence saying who moved it, one key per shape of actor.
     */
    private static function byWhom(WhoActed $who): string
    {
        return match ($who->shape) {
            ActorShape::Customer => 'business-mail.move_notice.by.customer',
            ActorShape::User => 'business-mail.move_notice.by.user',
            ActorShape::RemovedUser => 'business-mail.move_notice.by.removed',
            ActorShape::Support => 'business-mail.move_notice.by.support',
            ActorShape::System => 'business-mail.move_notice.by.system',
        };
    }
}
