<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What a service costs a customer: nothing, paid online in full, or paid on site. Spelled as
 * the services.payment_mode column stores it, and copied onto the booking at hold time so a
 * later service edit cannot restate what the customer agreed to. Unrelated to the tenant's
 * own subscription. The column's CHECK constraint lists the same three.
 */
enum PaymentMode: string
{
    case Free = 'free';
    case Online = 'online';
    case Onsite = 'onsite';
}
