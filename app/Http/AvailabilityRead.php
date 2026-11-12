<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Clock;
use App\Application\Dto\AvailabilityQuery;
use App\Application\Dto\AvailableStarts;
use App\Application\QueryAvailability;
use App\Domain\Booking\Actor;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresAvailabilityRepository;
use App\Infrastructure\Db\PostgresServiceRepository;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The engine's answer to the availability query on one tenant, before any caller shapes it:
 * every start on the granularity grid at which the whole chain can start.
 *
 * The use case is built for the tenant the request resolved, its two repositories bound to that
 * tenant at construction, the availability one over the mask cache on the default store. Opens
 * no transaction.
 *
 * Two routes take this query and both answer through here, so their answers cannot come apart
 * but where they mean to: the public read thins what this answers to the tenant's start interval
 * and draws on the services and members offered online, and the admin's one JSON endpoint
 * answers it whole over the whole catalogue (Pool) and for the business, so the lead time and
 * the horizon, which bind customers alone, drop nothing from it. The public read alone asks, of
 * an empty window, the first later day holding a start (next_available_from).
 */
final readonly class AvailabilityRead
{
    public function __construct(
        private DatabaseManager $db,
        private SettingsReader $settings,
        private StaffDayMaskCache $cache,
        private Clock $clock,
    ) {}

    /**
     * @param  Actor  $by  who the answer is for: a customer unless the caller says the business
     */
    public function query(Tenant $tenant, AvailabilityQuery $query, Pool $pool, Actor $by = Actor::Customer): AvailableStarts
    {
        return $this->useCase($tenant, $pool)->query($query, $by);
    }

    /**
     * The first tenant-local date after the query's window holding a start on the start
     * interval, inside the chain's smallest horizon, or null: read through the same masks and
     * the same cache as an answer.
     */
    public function nextAvailableFrom(Tenant $tenant, AvailabilityQuery $query, Pool $pool, int $startIntervalMin): ?string
    {
        return $this->useCase($tenant, $pool)->nextAvailableFrom($query, $startIntervalMin);
    }

    private function useCase(Tenant $tenant, Pool $pool): QueryAvailability
    {
        return new QueryAvailability(
            new PostgresServiceRepository($this->db, $tenant, $pool),
            new PostgresAvailabilityRepository($this->db, $this->settings, $tenant, $this->cache),
            $tenant,
            $this->clock,
        );
    }
}
