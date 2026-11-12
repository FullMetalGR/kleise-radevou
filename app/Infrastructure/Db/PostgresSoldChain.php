<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Repository\SoldChain;
use App\Tenancy\Tenant;

/**
 * The pre-read of a move, for one tenant, over the booking reader: what a booking was sold as,
 * outside any transaction. Built per tenant rather than resolved from the container, so a use
 * case holding one can never read another tenant's rows.
 */
final readonly class PostgresSoldChain implements SoldChain
{
    public function __construct(
        private BookingReader $reader,
        private Tenant $tenant,
    ) {}

    public function of(string $uuid): ?array
    {
        $items = $this->reader->soldItems($this->tenant, $uuid);

        return $items === [] ? null : $items;
    }
}
