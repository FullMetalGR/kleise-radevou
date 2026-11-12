<?php

declare(strict_types=1);

namespace App\Application\Notification;

use InvalidArgumentException;

/**
 * One place a message goes, and who is behind it.
 *
 * The identity is optional and the difference matters. A recipient that is a user can be acted as:
 * a decision link is signed per user, so an approve link in one person's mail is not an approve
 * link in another's. A staff row with an address and no login is a real recipient with nobody to
 * sign for, and it gets the message without the links only a user may follow.
 *
 * The address is kept as it was written and compared case-insensitively, because the column it
 * came from is citext and two rows differing only in case are one mailbox.
 *
 * What they may be shown of the booking travels with them for the same reason. Who is shown the
 * customer's contact details is one class's decision on every surface, and a message is a surface:
 * the customer's email and phone reach a recipient only where that recipient holds manageBookings,
 * so the answer has to arrive with the address rather than be guessed by whichever template is
 * being written. It is a plain answer and not a role, because Application depends on Domain and on
 * ports and on nothing else; the rule that reads the role is BusinessRecipients, where a role may
 * be read.
 *
 * So does the language a message to them is written in, its code, en or el: a user of the business
 * reads their own, users.locale else the tenant default, and a staff row's own address the tenant
 * default, each answered by the resolver when BusinessRecipients names them, so no template has to
 * ask which. A code and not the site's language type, for the reason the snapshot's language is
 * one: Application imports nothing of the site. English where nobody named one; a customer's
 * message is written in the booking's language and records it on the message, so the address it
 * goes to carries the default.
 */
final readonly class Recipient
{
    /**
     * @param  bool  $managesBookings  whether this mailbox belongs to somebody with manageBookings,
     *                                 which is what the customer's contact
     *                                 details hang on. False by default: a recipient nobody has
     *                                 classified is shown the less, never the more.
     * @param  string  $language  the code of the language a message to this mailbox is written in
     */
    public function __construct(
        public string $address,
        public ?int $userId = null,
        public bool $managesBookings = false,
        public string $language = 'en',
    ) {
        if (trim($address) === '') {
            throw new InvalidArgumentException('A recipient needs an address to write to.');
        }
    }

    /**
     * What decides whether two recipients are the same mailbox.
     */
    public function mailbox(): string
    {
        return strtolower(trim($this->address));
    }
}
