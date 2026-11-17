<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Which side wrote a help message: a user of the business, or the platform from the console. A
 * ticket keeps the side of its newest message, which the console's list reads as awaiting a reply.
 */
enum HelpSide: string
{
    case Business = 'business';
    case Platform = 'platform';
}
