<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\SubscriptionState;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;

/**
 * One row of the tenant list: the id, the slug, null on a closed row whose slug the drop
 * released, the name, the status, the subscription state, the plan TenantPlan derives, null
 * while the row is provisioning, and the signup instant.
 */
final readonly class ListedTenant
{
    public function __construct(
        public int $id,
        public ?string $slug,
        public string $name,
        public TenantStatus $status,
        public SubscriptionState $state,
        public ?TenantPlan $plan,
        public DateTimeImmutable $signedUpAt,
    ) {}

    /**
     * Where the console reads the row's detail: by its slug, or, on a closed row, which holds
     * none, by its id under /tenants/closed, the one address the row keeps that no later row
     * takes (its schema_name passes to a new signup of the released slug).
     */
    public function detailPath(): string
    {
        return $this->slug === null ? '/tenants/closed/'.$this->id : '/tenants/'.$this->slug;
    }
}
