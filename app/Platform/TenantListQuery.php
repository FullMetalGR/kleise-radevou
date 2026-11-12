<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\SubscriptionState;
use App\Tenancy\TenantStatus;

/**
 * What the tenant list is asked, read from the query string at the boundary: the search over the
 * slug and the name, the status, the subscription state and the plan each one of its words, the
 * two dates over the signup instant, each a calendar date in the console's zone with To
 * inclusive, and the page counted from one. A null is a field left unset, a malformed value
 * having been read as one.
 */
final readonly class TenantListQuery
{
    public function __construct(
        public ?string $search = null,
        public ?TenantStatus $status = null,
        public ?SubscriptionState $state = null,
        public ?TenantPlan $plan = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $page = 1,
    ) {}
}
