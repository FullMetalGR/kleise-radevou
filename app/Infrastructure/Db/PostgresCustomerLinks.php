<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\MyBookings\CustomerLinks;
use App\Tenancy\Tenant;
use DateTimeImmutable;

/**
 * The port over one tenant's rows: CustomerAccess bound to the tenant a factory built it for, so
 * a link and the bookings it was decided on can never belong to different tenants.
 */
final readonly class PostgresCustomerLinks implements CustomerLinks
{
    public function __construct(private CustomerAccess $access, private Tenant $tenant) {}

    public function hasBookings(string $email): bool
    {
        return $this->access->hasBookings($this->tenant, $email);
    }

    public function mint(string $email, string $tokenHash, DateTimeImmutable $createdAt, DateTimeImmutable $expiresAt): int
    {
        return $this->access->mintLink($this->tenant, $email, $tokenHash, $createdAt, $expiresAt);
    }
}
