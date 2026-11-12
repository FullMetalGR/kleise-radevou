<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * How a provider is connected: through the provider's partner onboarding with a per-transaction
 * fee, or with the tenant's own credentials and none.
 */
enum ConnectionMode: string
{
    case Platform = 'platform';
    case Own = 'own';
}
