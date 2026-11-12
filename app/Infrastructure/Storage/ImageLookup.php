<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Tenancy\Tenant;
use Closure;
use LogicException;

/**
 * Which stored images a tenant's rows hold, kind by kind: the one place a route that serves an
 * image asks whether a name is the tenant's to serve. Each kind is registered with a reader of
 * the paths its rows hold, so the kind a later column adds is one registration beside the logo's
 * in AppServiceProvider, and a name of a kind with no reader is held by nothing. A held path is
 * answered only when it is a file of the tenant's own directory, so a row naming another
 * tenant's file, or a path climbing out of its own, is never served.
 */
final class ImageLookup
{
    /** @var array<string, Closure(Tenant): list<string>> keyed by the kind's value */
    private array $readers = [];

    /**
     * @param  Closure(Tenant): list<string>  $held  the stored paths the tenant's rows hold as the kind
     *
     * @throws LogicException when the kind already has a reader
     */
    public function register(ImageKind $kind, Closure $held): void
    {
        if (isset($this->readers[$kind->value])) {
            throw new LogicException(sprintf('The %s images already have a reader, and a kind is read one way.', $kind->value));
        }

        $this->readers[$kind->value] = $held;
    }

    /** The stored path a row of the tenant holds as the kind under this file name, or null. */
    public function find(Tenant $tenant, ImageKind $kind, string $file): ?string
    {
        $held = $this->readers[$kind->value] ?? null;

        if ($held === null) {
            return null;
        }

        foreach ($held($tenant) as $path) {
            if (TenantImages::fileOf($path) === $file && TenantImages::isOwn($tenant, $path)) {
                return $path;
            }
        }

        return null;
    }
}
