<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The whole vocabulary of a platform audit payload: a key is one of these and nothing else. No key
 * names a person: the operator, the tenant, a user, a contact message and a help ticket are
 * references, the first two in columns of their own, a user by id under User, a message by id under
 * Message and a ticket by id under Ticket.
 *
 * Reason is the one free-text key, a suspension's, which an operator writes about a business and
 * may word about a person; it is treated as possibly personal and kept 24 months as the tenant
 * trail is.
 */
enum PlatformAuditKey: string
{
    /** The operator's own words for a suspension. */
    case Reason = 'reason';

    /** The feature slugs a tenant holds after a change. */
    case Features = 'features';

    /** The id of the tenant's user an action was about. */
    case User = 'user';

    /**
     * The id of the contact form message an action was about: the row by reference, never its
     * sender or a word of its content.
     */
    case Message = 'message';

    /**
     * The id of the help ticket an action was about: the ticket by reference, never its subject nor
     * a word of its messages.
     */
    case Ticket = 'ticket';
}
