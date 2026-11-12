<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The port's one adapter: the record goes to the framework's dispatcher, which is where a
 * notification listener subscribes and from where a queued job is pushed.
 *
 * It is built for one tenant and binds that tenant around the dispatch. A record carries the
 * booking and never the tenant, deliberately: it is built inside a use case, and a use case
 * never learns which tenant it is running for. The tenant is known here, because a publisher
 * is built by the same factory that binds a use case to its repositories, so this is the layer
 * that can answer it and the only one. A listener then reads the tenant the way a controller
 * does, whether it was reached from a request or from the sweeper's job.
 *
 * Everything the dispatcher raises is caught and logged. The transition has committed by the
 * time this runs, so there is nothing to undo and nobody to answer with an error: a listener
 * that throws is a fault to log, never a failed booking. The event's class is logged and the
 * event itself is not, because a record may name a booking and carry a credential, and a log
 * line is not a place to put either.
 *
 * On the demo nothing is announced at all: the record is dropped here, before any listener
 * runs and so before any job is queued, by the configured label and not by the tenant's
 * switches, which a switch turned on must not undo. One line names the label and the record's
 * class, and never an address. The provider's listener goes unheard with the rest, which costs
 * nothing, since the demo connects no provider.
 */
final readonly class DispatchedTransitions implements TransitionPublisher
{
    public function __construct(
        private Dispatcher $events,
        private LoggerInterface $log,
        private CurrentTenant $current,
        private Demo $demo,
        private Tenant $tenant,
    ) {}

    public function publish(TransitionEvent $event): void
    {
        if ($this->demo->is($this->tenant)) {
            $this->log->info('A transition on the demo was not announced', ['tenant' => $this->tenant->slug, 'event' => $event::class]);

            return;
        }

        try {
            $this->current->during($this->tenant, fn () => $this->events->dispatch($event));
        } catch (Throwable $thrown) {
            $this->log->error('A transition listener failed', [
                'tenant' => $this->tenant->slug,
                'event' => $event::class,
                'exception' => $thrown::class,
                'message' => $thrown->getMessage(),
            ]);
        }
    }
}
