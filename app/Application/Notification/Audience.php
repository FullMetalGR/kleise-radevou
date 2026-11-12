<?php

declare(strict_types=1);

namespace App\Application\Notification;

/**
 * Who a message is written for.
 *
 * The distinction is not decoration. The customer and the business hear about the same
 * transition in different words, from different addresses, and a tenant switching one off must
 * not switch off the other, which is why every key of the catalog is its own string and why
 * each one declares which side it speaks to.
 */
enum Audience: string
{
    case Customer = 'customer';
    case Business = 'business';
}
