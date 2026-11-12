<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Tenancy\CurrentTenant;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * How a listener hands a message to the queue: with the tenant it heard about, and without ever
 * throwing.
 *
 * **A listener runs after its transition has committed**, so there is nothing left to roll back
 * and nobody left to answer with an error: a message that could not be queued is a fault to
 * log, never a booking that failed. Handing a job to the queue is not the quiet operation it
 * looks like - the database driver's push is a query, and under the sync driver the job runs
 * inside the call - so this is where that reaches.
 *
 * **And every listener on a record runs whether or not another one failed.** The framework's
 * dispatcher calls them in turn and stops at the first throw, so a listener that lets one out
 * silences the ones behind it: the customer's confirmation failing would take the business's
 * notice with it, although the two have nothing to do with each other. Catching here is what
 * keeps them independent, and it is one class rather than one try in each listener so that a
 * listener added later cannot forget.
 *
 * The line names the tenant, the job and the booking, and never an address and never the token,
 * the way every other line here does. The job is named under its own key, job, because the
 * publisher's line one level up names an exception's message under message, and one key means
 * one thing across these lines. The exception's class is kept because it separates a queue that
 * was not there from a message that could not be built; the booking is what an operator
 * follows.
 *
 * The tenant comes from the binding the publisher makes around the dispatch: a transition
 * record names the booking and never the tenant, because it is built inside a use case and a
 * use case never learns which tenant it runs for. Reading it is inside the guard too, since a
 * record dispatched with nothing bound is a fault of the same kind.
 */
final readonly class QueuedMessages
{
    public function __construct(private CurrentTenant $current, private LoggerInterface $log) {}

    /**
     * @param  class-string  $job  the job carrying the message, so a failed line says which one
     * @param  string  $booking  the uuid the message is about, which is what a failure is logged against
     * @param  callable(int): void  $hand  hands the job to the queue, given the tenant id it must carry
     */
    public function hand(string $job, string $booking, callable $hand): void
    {
        $tenant = null;

        try {
            $tenant = $this->current->get();

            $hand($tenant->id);
        } catch (Throwable $failure) {
            $this->log->error('A message could not be queued', [
                'tenant' => $tenant?->slug,
                'job' => $job,
                'booking' => $booking,
                'exception' => $failure::class,
            ]);
        }
    }
}
