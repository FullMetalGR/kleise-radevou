<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Repository\BookingLookup;
use App\Tenancy\Tenant;

/**
 * The pre-read of a guarded transition, for one tenant, over the booking reader: the contended
 * ranges of a booking's items, outside any transaction, and nothing else about the booking.
 * Built per tenant rather than resolved from the container, so a use case holding one can never
 * read another tenant's rows.
 */
final readonly class PostgresBookingLookup implements BookingLookup
{
    public function __construct(
        private BookingReader $reader,
        private Tenant $tenant,
    ) {}

    public function blocksOf(string $uuid): ?array
    {
        $blocks = $this->reader->staffBlocks($this->tenant, $uuid);

        return $blocks === [] ? null : $blocks;
    }
}
