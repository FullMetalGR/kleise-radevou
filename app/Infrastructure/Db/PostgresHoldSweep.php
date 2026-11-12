<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Repository\HoldSweep;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Database\Connection;

/**
 * The sweeper's transactions for one tenant: each batch goes through the same runner every
 * booking write goes through, so the schema is set and asserted, READ COMMITTED is asserted, and
 * the lock and statement timeouts are the protocol's. Built per tenant rather than resolved from
 * the container, so a sweep holding one can never write to another tenant's schema, and the
 * advisory key it takes can never name a tenant other than the one it is sweeping.
 *
 * Nothing is warmed afterwards and nothing is cached. The sweep invalidates no availability
 * read, because every mask that counted one of these holds as busy has already lapsed by the
 * same clock reading that selected the row.
 */
final readonly class PostgresHoldSweep implements HoldSweep
{
    public function __construct(
        private TransactionRunner $runner,
        private Tenant $tenant,
    ) {}

    public function transaction(Closure $work): mixed
    {
        return $this->runner->run(
            $this->tenant,
            fn (Connection $connection): mixed => $work(new PostgresSweepBatch($connection, $this->tenant->id)),
        );
    }
}
