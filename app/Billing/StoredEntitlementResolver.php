<?php

declare(strict_types=1);

namespace App\Billing;

use App\Tenancy\Tenant;
use InvalidArgumentException;

/**
 * The stored binding of the resolver: the tenant row and nothing else. While the cached
 * subscription state is active, grace or trial, the stored slugs filtered to the features this
 * build knows; in every other state the empty set, which is the floor. A trial's stored set is
 * the full one provisioning wrote. It touches no network and no second table, because tenant
 * resolution has already loaded the row it reads.
 *
 * Two axes, one line: the state decides whether the stored set counts at all, and the stored
 * set decides what exists. A state outside the six and a stored value that is not an array
 * literal are the row saying badly what was bought, and both fall closed to the floor, which
 * always works.
 */
final readonly class StoredEntitlementResolver implements EntitlementResolver
{
    public function resolve(Tenant $tenant): Entitlements
    {
        if (SubscriptionState::tryFrom($tenant->subscription_state)?->isActive() !== true) {
            return Entitlements::none();
        }

        try {
            return Entitlements::fromSlugs($tenant->entitlements);
        } catch (InvalidArgumentException) {
            return Entitlements::none();
        }
    }
}
