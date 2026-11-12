<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\Entitlements;
use App\Billing\SubscriptionState;
use App\Tenancy\BusinessCategory;
use App\Tenancy\TenantStatus;
use DateTimeImmutable;

/**
 * One tenant read whole: the central row's columns, the suspension while suspended, the owner's
 * acceptance of the terms where the signup recorded one, the plan TenantPlan derives, null while
 * provisioning, the feature set the row stores whatever the state, the subscriptions row's facts
 * where one exists, every users row in the order they were made, and what the tenant's own schema
 * holds, null for a provisioning row, which has no schema yet, and for a closed one, whose schema
 * the drop removed with its users and its slug: its slug null, its closed_at set and its users
 * none.
 */
final readonly class TenantFacts
{
    /**
     * @param  list<TenantPerson>  $users
     */
    public function __construct(
        public ?string $slug,
        public string $name,
        public TenantStatus $status,
        public string $timezone,
        public string $currency,
        public ?BusinessCategory $category,
        public DateTimeImmutable $signedUpAt,
        public ?DateTimeImmutable $trialEndsAt,
        public ?DateTimeImmutable $graceEndsAt,
        public ?DateTimeImmutable $closingAt,
        public ?DateTimeImmutable $closedAt,
        public ?TenantSuspension $suspension,
        public ?TenantTerms $terms,
        public SubscriptionState $state,
        public ?TenantPlan $plan,
        public Entitlements $entitlements,
        public ?TenantSubscription $subscription,
        public array $users,
        public ?TenantActivity $activity,
    ) {}
}
