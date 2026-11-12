<?php

declare(strict_types=1);

namespace App\Billing;

use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use UnexpectedValueException;

/**
 * A tenant's subscriptions row as the Billing screen and the plan change read it: the provider's
 * customer, the subscription once a snapshot has named it, and the revision the row stands at,
 * which a plan change reads before it asks the provider and hands to the sync. The console's
 * tenant:provision writes no row at all, which reads as null.
 */
final readonly class SubscriptionRow
{
    public function __construct(
        public string $customerId,
        public ?string $subscriptionId,
        public int $revision,
    ) {}

    public static function of(DatabaseManager $db, Tenant $tenant): ?self
    {
        $row = $db->connection()->selectOne(
            'select provider_customer_id, provider_subscription_id, revision from subscriptions where tenant_id = ?',
            [$tenant->id],
        );

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        $customer = $row['provider_customer_id'] ?? null;
        $subscription = $row['provider_subscription_id'] ?? null;
        $revision = $row['revision'] ?? null;

        if (! is_string($customer) || ! is_int($revision)) {
            throw new UnexpectedValueException(sprintf('The subscriptions row of tenant "%s" names no customer or no revision.', $tenant->slug));
        }

        return new self($customer, is_string($subscription) ? $subscription : null, $revision);
    }
}
