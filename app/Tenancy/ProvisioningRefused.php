<?php

declare(strict_types=1);

namespace App\Tenancy;

use RuntimeException;

/**
 * The slug belongs to a tenant in a state provisioning must not touch. A suspended or closing
 * tenant is somebody's decision, and a webhook redelivery must not reverse it. A row a signup
 * reserved is its verification link's to build, never the console's.
 */
final class ProvisioningRefused extends RuntimeException
{
    private function __construct(public readonly Tenant $tenant, string $message)
    {
        parent::__construct($message);
    }

    public static function because(Tenant $tenant): self
    {
        return new self($tenant, sprintf(
            'Tenant "%s" is %s and cannot be provisioned.',
            $tenant->slug,
            $tenant->status->value,
        ));
    }

    public static function heldBySignup(Tenant $tenant): self
    {
        return new self($tenant, sprintf(
            'Tenant "%s" is held by a signup in progress and cannot be provisioned from the console.',
            $tenant->slug,
        ));
    }
}
