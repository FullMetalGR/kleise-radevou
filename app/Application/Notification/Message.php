<?php

declare(strict_types=1);

namespace App\Application\Notification;

use InvalidArgumentException;

/**
 * One message, ready to send: which of the catalog's messages it is, the one place it goes,
 * what it says, the booking it is about, and any files it carries.
 *
 * One recipient and not a list, deliberately. The messages differ per person - a decision link is
 * signed per user, so two approvers receive two different bodies - and a send that fails for one
 * address must not silence the others. A rule that names several people produces several
 * messages, and the recipient set is where an address named twice is reduced to one.
 *
 * The booking's identifier rides along although no template needs it. It is what a failure is
 * logged against: the seam may never say which address a message was going to, so the booking
 * is the only handle an operator has on a message that did not arrive.
 *
 * One key names no booking, my_bookings_link: the link a customer asks for on the hosted page
 * opens every booking of their address, and no one of them is what it is about. It takes a null
 * identifier, and it alone: every other key is a booking's message and is refused without one,
 * and the link's is refused with one.
 *
 * The language is the one the message is written in, its code, en or el: a customer's message the
 * booking's, the bookings link the language of the request that asked for it, a message to a user
 * of the business the recipient's. English where a builder names none; every customer message
 * names its own, and every business message its recipient's. Nothing reads it yet: it records
 * what the words were written in.
 */
final readonly class Message
{
    /**
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public MessageKey $key,
        public Recipient $to,
        public string $subject,
        public string $body,
        public ?string $bookingUuid,
        public array $attachments = [],
        public string $language = 'en',
    ) {
        if (trim($subject) === '' || trim($body) === '') {
            throw new InvalidArgumentException('A message needs a subject and a body.');
        }

        if (($bookingUuid === null) !== ($key === MessageKey::MyBookingsLink)) {
            throw new InvalidArgumentException('A message names its booking, except the bookings link, which names none.');
        }
    }
}
