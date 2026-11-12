<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * The surface a booking came through: the hosted page, the embed on somebody else's site, or the
 * admin's manual booking. Spelled as the column stores it, whose CHECK lists the same three in
 * this order and whose missing default makes every writer name one.
 *
 * The server stamps it and no client names its own: the public hold reads the request's Origin, or
 * failing that its Referer's origin, and calls the tenant's own origin hosted and anything else,
 * no header included, embed; the admin's create is admin.
 */
enum BookingSource: string
{
    case Hosted = 'hosted';
    case Embed = 'embed';
    case Admin = 'admin';
}
