<?php

declare(strict_types=1);

namespace App\Platform;

use App\Tenancy\InvalidSlug;
use App\Tenancy\Slug;
use App\Tenancy\SlugRules;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;

/**
 * The tenant a console path names by its slug: the detail and every write on it read the path's
 * slug here. A slug of the wrong shape, a reserved one, whether or not a row holds it, as the
 * tenant resolver refuses it, and one no row holds name no tenant, and the caller answers the
 * console's 404. The slug is read whatever its case, as the column does.
 */
final readonly class TenantLookup
{
    public function __construct(private SlugRules $rules) {}

    public function bySlug(string $slug): ?Tenant
    {
        try {
            $valid = Slug::of($slug);
        } catch (InvalidSlug) {
            return null;
        }

        return $this->rules->isReserved($valid) ? null : Tenant::query()->where('slug', $valid->value)->first();
    }

    /**
     * The closed tenant a console path names by its id: a closed row holds no slug, the drop
     * having released it, and is read by the id it keeps. A path that is not an id, and an id
     * naming no row or a row that is not closed, which the slug addresses, name no tenant.
     */
    public function closed(string $id): ?Tenant
    {
        if (preg_match('/\A[1-9][0-9]{0,17}\z/', $id) !== 1) {
            return null;
        }

        return Tenant::query()->whereKey((int) $id)->where('status', TenantStatus::Closed->value)->first();
    }
}
