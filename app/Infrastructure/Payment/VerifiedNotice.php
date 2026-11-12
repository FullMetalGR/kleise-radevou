<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Payment\PaymentNotice;
use App\Tenancy\Tenant;

/**
 * A notification the webhook's access check verified: the provider in its path, the tenant its
 * token routed to, and the notice the adapter read.
 */
final readonly class VerifiedNotice
{
    public function __construct(
        public string $provider,
        public Tenant $tenant,
        public PaymentNotice $notice,
    ) {}
}
