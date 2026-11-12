<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Tenancy\Tenant;
use InvalidArgumentException;

/**
 * A path the image store will not read or delete for a tenant: it is not a file of the tenant's
 * own directory. Every path the store is handed is one a row of the tenant holds and the store
 * wrote, so this is a row that was written some other way, and it fails loudly rather than
 * reaching the disk.
 */
final class ImagePathRefused extends InvalidArgumentException
{
    public static function outside(Tenant $tenant, string $path): self
    {
        return new self(sprintf('The image path %s is not a file of tenant "%s"\'s own directory, %s.', $path, $tenant->slug, TenantImages::directory($tenant)));
    }
}
