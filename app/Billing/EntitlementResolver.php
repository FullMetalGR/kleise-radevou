<?php

declare(strict_types=1);

namespace App\Billing;

use App\Tenancy\Tenant;

/**
 * Where a tenant's entitlement set comes from, as every screen and gate reads it.
 *
 * A port so that the real implementation swaps in by one binding and touches no screen: the
 * admin reads the answer through the shared entitlements prop and never asks the row itself.
 * Whatever the binding, an answer of Entitlements::none() leaves the floor working, because
 * the floor is not a feature.
 */
interface EntitlementResolver
{
    public function resolve(Tenant $tenant): Entitlements;
}
