<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Application\MyBookings\BookingsLinkRequested;
use App\Application\MyBookings\LinkAnnouncer;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The announcer's one adapter, built as DispatchedTransitions is: the record goes to the
 * framework's dispatcher with the tenant bound around the dispatch, where the bookings link's
 * listener reads it, and whatever the dispatcher raises is caught and logged. The row is written
 * by then and the form answers the same sentence whatever happens here.
 *
 * The line names the tenant, the record's class and the exception's class, and nothing else: the
 * record carries the plaintext token, and neither it nor a message that might quote it belongs in
 * a log.
 *
 * On the demo nothing is announced, as DispatchedTransitions announces nothing there: the demo's
 * link POST mints no row and reaches no announcer, so this is the dispatch's own promise rather
 * than the road the demo takes, kept so that no record for the demo can reach a listener whatever
 * minted it. One line names the label and the record's class.
 */
final readonly class DispatchedLinkRequests implements LinkAnnouncer
{
    public function __construct(
        private Dispatcher $events,
        private LoggerInterface $log,
        private CurrentTenant $current,
        private Demo $demo,
        private Tenant $tenant,
    ) {}

    public function announce(BookingsLinkRequested $requested): void
    {
        if ($this->demo->is($this->tenant)) {
            $this->log->info('A bookings link on the demo was not announced', ['tenant' => $this->tenant->slug, 'event' => $requested::class]);

            return;
        }

        try {
            $this->current->during($this->tenant, fn () => $this->events->dispatch($requested));
        } catch (Throwable $thrown) {
            $this->log->error('A bookings link listener failed', [
                'tenant' => $this->tenant->slug,
                'event' => $requested::class,
                'exception' => $thrown::class,
            ]);
        }
    }
}
