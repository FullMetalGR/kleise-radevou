<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Clock;
use App\Application\ExpireHolds;
use App\Application\Payment\SettlesByProvider;
use App\Infrastructure\Db\PostgresHoldSweep;
use App\Infrastructure\Db\SettingsReader;
use App\Infrastructure\Db\TransactionRunner;
use App\Infrastructure\Events\TransitionPublishers;
use App\Tenancy\Tenant;

/**
 * The one place the sweeper is built for a tenant: its transactions bound to that one tenant, so
 * a sweep and the schema it writes to can never be different tenants', and the advisory key it
 * claims can only ever be that tenant's.
 *
 * Separate from BookingTransitionFactory, which builds the transitions of one booking. This
 * builds a job that settles up to five hundred of them and asks nothing about any of them: no
 * catalog read, no mask cache, no lock manager, no pre-read.
 */
final readonly class ExpireHoldsFactory
{
    public function __construct(
        private TransactionRunner $runner,
        private Clock $clock,
        private TransitionPublishers $events,
        private PaymentFactory $payments,
        private SettingsReader $settings,
    ) {}

    public function for(Tenant $tenant): ExpireHolds
    {
        return new ExpireHolds(
            new PostgresHoldSweep($this->runner, $tenant),
            $this->clock,
            $this->events->for($tenant),
            new SettlesByProvider($this->payments->provider($tenant)),
            fn (): int => $this->settings->read($tenant)->paymentTtlHours,
        );
    }
}
