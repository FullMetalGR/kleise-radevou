<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Where a customer followed a hosted-page link from: the fixed list a tenant tags a link with as
 * ?ch=, read on the hosted page alone and stored only when it is one of these five. Anything
 * else is no channel and is never a refusal, so a reader asks tryFrom and keeps the null. The
 * column's CHECK lists the same five in this order.
 */
enum BookingChannel: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Google = 'google';
    case Qr = 'qr';
    case Other = 'other';
}
