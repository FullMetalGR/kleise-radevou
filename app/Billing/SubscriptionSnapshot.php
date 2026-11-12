<?php

declare(strict_types=1);

namespace App\Billing;

use DateTimeImmutable;

/**
 * A subscription as the billing provider reported it, in a value of ours: its identifier and its
 * customer's, the provider's own status string verbatim, the end of the period paid for as an
 * instant, and the line items as prices with quantities. The sync resolves it onto the tenant row
 * and keeps it on the subscriptions row, so a resolve can be replayed without a network call.
 */
final readonly class SubscriptionSnapshot
{
    /**
     * @param  list<SnapshotItem>  $items
     */
    public function __construct(
        public string $subscriptionId,
        public string $customerId,
        public string $status,
        public DateTimeImmutable $periodEnd,
        public array $items,
    ) {}
}
