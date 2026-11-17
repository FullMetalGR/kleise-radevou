<?php

declare(strict_types=1);

namespace App\Help;

/**
 * The two limits on what one user writes in any hour, counted from the stored rows at the write:
 * new tickets, and messages across all of the business's threads, a ticket's first message among
 * them. A route throttle cannot count them, since it runs before the user is known.
 */
enum HelpLimit: string
{
    case Tickets = 'tickets';
    case Messages = 'messages';

    /** How many of this kind one user may write in any hour. */
    public function most(): int
    {
        return match ($this) {
            self::Tickets => 10,
            self::Messages => 60,
        };
    }
}
