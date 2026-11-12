<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A verified inbound notification from the billing provider, in a value of ours: the provider's
 * event id, which is the idempotency key, its own event type verbatim for the record, the kind
 * of event it is, and the customer and subscription it names, where it names them.
 */
final readonly class BillingNotice
{
    public function __construct(
        public string $eventId,
        public string $type,
        public NoticeKind $kind,
        public ?string $customerId,
        public ?string $subscriptionId,
    ) {}
}
