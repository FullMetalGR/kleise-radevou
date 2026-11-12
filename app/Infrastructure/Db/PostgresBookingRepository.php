<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Repository\BookingRepository;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Database\Connection;

/**
 * The booking repository of one tenant: every transaction it opens goes through the runner for
 * that tenant, and the writer it hands the work is bound to the runner's connection. Built per
 * tenant rather than resolved from the container, so a use case holding one can never write to
 * another tenant's schema.
 *
 * After the commit, and only then, the masks the writer kept go into the cache under the
 * revisions the lock returned. The runner has returned by then, so the mutex rows are released
 * and the store is touched with nothing held; a transaction that threw stores nothing, since
 * the exception leaves here before the store is reached, and a retried transaction stores
 * what its last attempt kept.
 */
final readonly class PostgresBookingRepository implements BookingRepository
{
    public function __construct(
        private TransactionRunner $runner,
        private LockManager $locks,
        private Tenant $tenant,
        private StaffDayMaskCache $cache,
    ) {}

    public function transaction(Closure $work): mixed
    {
        [$result, $writer] = $this->runner->run(
            $this->tenant,
            function (Connection $connection) use ($work): array {
                $writer = new PostgresBookingWriter($connection, $this->locks, $this->tenant);

                return [$work($writer), $writer];
            },
        );

        $writer->storeKept($this->cache);

        return $result;
    }
}
