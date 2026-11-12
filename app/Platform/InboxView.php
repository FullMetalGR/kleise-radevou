<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The two views of the console's contact messages: the open messages, those no operator has
 * archived, which is the screen's default, and the archived ones. The value is the screen's
 * ?view= as the URL spells it; anything else reads as Open.
 */
enum InboxView: string
{
    case Open = 'open';
    case Archived = 'archived';
}
