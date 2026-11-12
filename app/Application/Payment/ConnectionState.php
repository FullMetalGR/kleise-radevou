<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * Where a connection stands. Restricted is a connection the provider has flagged as needing
 * something from the tenant. Only an active one takes money.
 */
enum ConnectionState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Restricted = 'restricted';
    case Disabled = 'disabled';
}
