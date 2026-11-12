<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * How a platform-mode connection is started. Every provider verified so far starts one the
 * same way: the adapter answers a link to the provider's own hosted onboarding and the tenant
 * is sent there.
 */
enum OnboardingEntry: string
{
    case HostedLink = 'hosted_link';
}
