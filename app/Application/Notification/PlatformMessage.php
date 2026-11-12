<?php

declare(strict_types=1);

namespace App\Application\Notification;

use InvalidArgumentException;

/**
 * One of the platform's own messages, ready to send: which kind it is, the tenant it is about,
 * the one address it goes to and the name behind it, and what it says, in plain text, with the
 * same words as HTML beside it where the mail is one of the branded ones.
 *
 * Its reply-to is optional and null on every kind but one: the contact form's message, which the
 * support mailbox answers by replying to the person who wrote it. Every other message goes out
 * with none, so a reply reaches the platform's own address. A reply-to that is given is an
 * address; a blank one is refused here, as a blank recipient is.
 *
 * Its HTML part is optional too: the tenant-facing mails carry the text as HTML in the one branded
 * layout, rendered by the mail that composed it, and the adapter sends a multipart where it is
 * present; the operator's mails, the console's sign-in notice and the contact form's message,
 * carry none and go out as text alone. A blank one is refused as a blank body is, since a
 * multipart whose HTML part says nothing shows the reader nothing.
 *
 * Narrower than a tenant's Message on purpose. It carries no booking, because none of these is
 * about one; no files; and no key, because the tenant holds no switch over a message the platform
 * sends about the tenant's own account. The tenant's id rides along although no template needs it,
 * for the reason a booking's uuid rides on a Message: it is what a failure is logged against,
 * since the seam may never say which address a message was going to. It is null for a message
 * about no tenant, the operator's sign-in notice, whose failure is logged against the kind alone.
 */
final readonly class PlatformMessage
{
    public function __construct(
        public PlatformMessageKind $kind,
        public ?int $tenantId,
        public string $toAddress,
        public string $toName,
        public string $subject,
        public string $body,
        public ?string $replyTo = null,
        public ?string $html = null,
    ) {
        if (trim($toAddress) === '') {
            throw new InvalidArgumentException('A platform message needs an address to write to.');
        }

        if ($replyTo !== null && trim($replyTo) === '') {
            throw new InvalidArgumentException('A platform message\'s reply-to is an address or none.');
        }

        if (trim($subject) === '' || trim($body) === '') {
            throw new InvalidArgumentException('A platform message needs a subject and a body.');
        }

        if ($html !== null && trim($html) === '') {
            throw new InvalidArgumentException('A platform message\'s HTML part says something or is none.');
        }
    }
}
