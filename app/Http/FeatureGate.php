<?php

declare(strict_types=1);

namespace App\Http;

use App\Billing\EntitlementResolver;
use App\Billing\Feature;
use App\Tenancy\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The refusal of a route whose thing does not exist without its feature: the route answers
 * not-found, as for a row nobody has, rather than a form or a write the tenant did not buy.
 * Entitlements decide what exists; the subscription gate beside the permission on the route
 * decides whether a configuration write is allowed, and the two are never one check.
 *
 * It is the controller's refusal and not a middleware's, so every admin route still declares its
 * one permission and nothing else, and a controller asks it before it resolves a form request,
 * so a body that would not validate is not-found too rather than a 400 that tells a caller the
 * route is there.
 */
final readonly class FeatureGate
{
    public function __construct(
        private CurrentTenant $current,
        private EntitlementResolver $entitlements,
    ) {}

    public function require(Feature $feature): void
    {
        if (! $this->entitlements->resolve($this->current->get())->allows($feature)) {
            throw new NotFoundHttpException;
        }
    }
}
