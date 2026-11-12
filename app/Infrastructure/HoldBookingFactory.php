<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Clock;
use App\Application\HoldBooking;
use App\Application\HoldPlanner;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresAvailabilityRepository;
use App\Infrastructure\Db\PostgresBookingRepository;
use App\Infrastructure\Db\PostgresServiceRepository;
use App\Infrastructure\Db\PostgresSettingsRepository;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\TransactionRunner;
use App\Infrastructure\Events\TransitionPublishers;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;

/**
 * The one place the hold use case is built for a tenant: the catalog, availability and settings
 * reads, the booking repository and the planner, every one of them bound to the one tenant
 * given, so a use case and a repository for different tenants can never be paired. Resolved
 * from the container for its shared parts, the runner, the lock manager, the readers, the mask
 * cache on the default store, the clock and the publishers every committed transition goes out
 * through, and asked for a tenant per request. The publisher is built for the same tenant, so a
 * transition can only ever be announced as the tenant it happened to. The caller names the pool
 * too, Pool::Online on the public road and Pool::Whole on the admin's, since that is where the
 * two roads part.
 */
final readonly class HoldBookingFactory
{
    public function __construct(
        private DatabaseManager $db,
        private TransactionRunner $runner,
        private LockManager $locks,
        private SettingsReader $settings,
        private StaffDayMaskCache $cache,
        private Clock $clock,
        private TransitionPublishers $events,
    ) {}

    public function for(Tenant $tenant, Pool $pool): HoldBooking
    {
        return new HoldBooking(
            $this->planner($tenant, $pool),
            new PostgresBookingRepository($this->runner, $this->locks, $tenant, $this->cache),
            $tenant,
            $this->events->for($tenant),
        );
    }

    /**
     * The planner alone, for the one other write that places a chain. A move plans the same way
     * a hold does, from the catalog and the advisory masks, so it is built here rather than
     * assembled a second time somewhere else: two places assembling a planner is two places to
     * hand one a reader for the wrong tenant.
     */
    public function planner(Tenant $tenant, Pool $pool): HoldPlanner
    {
        return new HoldPlanner(
            new PostgresServiceRepository($this->db, $tenant, $pool),
            new PostgresAvailabilityRepository($this->db, $this->settings, $tenant, $this->cache),
            new PostgresSettingsRepository($this->settings, $tenant),
            $tenant,
            $this->clock,
        );
    }
}
