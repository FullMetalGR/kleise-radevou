<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Event\TransitionPublisher;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * The one place a publisher is built for a tenant.
 *
 * A use case takes the port and never the tenant, so the pairing has to happen where a use case
 * is assembled, which is the three factories. Building it here rather than in each of them
 * keeps one answer to what a publisher is, and makes it impossible to hand a use case for one
 * tenant a publisher announcing for another.
 */
final readonly class TransitionPublishers
{
    public function __construct(
        private Dispatcher $events,
        private LoggerInterface $log,
        private CurrentTenant $current,
        private Demo $demo,
    ) {}

    public function for(Tenant $tenant): TransitionPublisher
    {
        return new DispatchedTransitions($this->events, $this->log, $this->current, $this->demo, $tenant);
    }
}
