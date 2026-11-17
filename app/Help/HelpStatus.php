<?php

declare(strict_types=1);

namespace App\Help;

/**
 * A help ticket's status. Only the console closes one; the console reopens one, and so does any
 * new message from the business.
 */
enum HelpStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
