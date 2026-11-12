<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;

/**
 * A tenant's subscriptions row as the console's detail reads it: the billing provider's customer,
 * the provider's own status string, stored verbatim and null until the first snapshot, and the
 * current period's end, null until then too. Billing's columns, read and never written here.
 */
final readonly class TenantSubscription
{
    public function __construct(
        public string $customerId,
        public ?string $status,
        public ?DateTimeImmutable $currentPeriodEnd,
    ) {}
}
