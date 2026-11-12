<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * One line of a subscription as the billing provider last reported it: the price it is sold
 * under and how many of it. A value of ours, so nothing past the adapter reads the provider's
 * own shape. It holds what it was given; whether it can be read is the catalogue's question,
 * because a malformed line is a snapshot the sync refuses to resolve rather than one it
 * cannot hold.
 */
final readonly class SnapshotItem
{
    public function __construct(
        public string $priceId,
        public int $quantity,
    ) {}
}
