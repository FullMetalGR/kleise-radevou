<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * How the platform's fee is attached in platform mode: a parameter on each payment, the
 * partner agreement itself, or not at all. A provider with no mechanism does not offer
 * platform mode, which the registry holds at registration.
 */
enum FeeMechanism: string
{
    case None = 'none';
    case Parameter = 'parameter';
    case Agreement = 'agreement';
}
