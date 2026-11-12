<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use App\Application\Event\BookingCancelled;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingReleased;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\TransitionEvent;
use App\Domain\Enum\HoldClass;
use App\Jobs\ExpireCheckout;
use App\Jobs\FlagPayment;
use App\Tenancy\CurrentTenant;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a committed transition asks of the tenant's payment provider. Two follow-ups, each a
 * queued job that re-reads the booking before it asks, and at most one of them per record:
 *
 * - **The expiry**, ExpireCheckout, on the record of a transition that ended a checkout or a
 *   payment hold, the only holds a session is opened for: a lapse (BookingExpired, the sweeper's
 *   and a reap's alike), a release (BookingReleased), a cancel of a held booking
 *   (BookingCancelled from a held status) and the on-site settlement at the hop (BookingConfirmed
 *   from a held status saying afterPayment, which the paid transition's confirmation says too).
 *   The job expires the session recorded for the booking where no payment landed for it, so a
 *   paid confirmation asks nothing. The booking app's confirm queues nothing: it settles an
 *   online hold unpaid wherever the tenant's provider answers unavailable, and its record cannot
 *   tell a hold carrying a session from the free, on-site and null-provider online holds it
 *   confirms, which carry none, so a job for each would ask nothing. The one session left to its
 *   own end that way is an earlier press's, where the connection works and its provider answers
 *   unavailable; a payment landing at it is a late payment. A move of a booking still waiting for
 *   its payment that carried a session (BookingRescheduled naming the session it cleared) queues
 *   the same job for that session.
 * - **The flag**, FlagPayment, on a cancel of a booking no longer held, whoever cancelled: the
 *   payment that landed for it is flagged with a note, never refunded. A held booking carries no
 *   payment, since a payment that lands on a live hold confirms it, so its cancel asks the
 *   expiry alone.
 *
 * Every other record passes through untouched: a reject, an approval, a move that cleared no
 * session, a hold, a completion, a no-show, a late payment. The payment webhook queues the late
 * payment's flag itself.
 *
 * It subscribes to the marker rather than to each record, because what the provider is told is
 * no message: the per-record listeners are the notifications' alone, and NotificationMatrixTest
 * pins them as such. The records are published after the commit, a sweep's after each batch's
 * and a hold's or a move's reap after that write's, so the job runs outside any transaction and
 * under no staff-day lock, and the queue it goes through is one job per booking.
 *
 * It never throws: the transition has committed, so a job that could not be queued is a fault to
 * log against the booking. The tenant is the one the publisher binds around the dispatch.
 */
final readonly class TellTheProvider
{
    public function __construct(private CurrentTenant $current, private LoggerInterface $log) {}

    public function handle(TransitionEvent $event): void
    {
        if ($event instanceof BookingRescheduled) {
            $this->expireMoved($event);

            return;
        }

        if (! $event instanceof BookingExpired && ! $event instanceof BookingReleased && ! $event instanceof BookingCancelled && ! $event instanceof BookingConfirmed) {
            return;
        }

        $job = self::followUp($event);

        if ($job === null) {
            return;
        }

        $tenant = null;

        try {
            $tenant = $this->current->get();

            $job === ExpireCheckout::class
                ? ExpireCheckout::dispatch($tenant->id, $event->uuid)
                : FlagPayment::dispatch($tenant->id, $event->uuid);
        } catch (Throwable $failure) {
            $this->log->error('A payment follow-up could not be queued', [
                'tenant' => $tenant?->slug,
                'job' => $job,
                'booking' => $event->uuid,
                'exception' => $failure::class,
            ]);
        }
    }

    /**
     * A move's follow-up: the expiry of the session it cleared, named on the job, since the row
     * no longer names it; nothing for a move that cleared none.
     */
    private function expireMoved(BookingRescheduled $event): void
    {
        if ($event->session === null) {
            return;
        }

        $tenant = null;

        try {
            $tenant = $this->current->get();
            ExpireCheckout::dispatch($tenant->id, $event->uuid, $event->session);
        } catch (Throwable $failure) {
            $this->log->error('A payment follow-up could not be queued', [
                'tenant' => $tenant?->slug,
                'job' => ExpireCheckout::class,
                'booking' => $event->uuid,
                'exception' => $failure::class,
            ]);
        }
    }

    /**
     * The one job a record asks for, or null: the expiry where the transition ended a checkout
     * or a payment hold, a confirmation only where it says afterPayment, and the flag where a
     * booking no longer held was cancelled.
     *
     * @return class-string<ExpireCheckout|FlagPayment>|null
     */
    private static function followUp(BookingExpired|BookingReleased|BookingCancelled|BookingConfirmed $event): ?string
    {
        if ($event instanceof BookingConfirmed && ! $event->afterPayment) {
            return null;
        }

        if ($event->from?->isHeld() === true) {
            return in_array($event->holdClass, [HoldClass::Checkout, HoldClass::Payment], true) ? ExpireCheckout::class : null;
        }

        return $event instanceof BookingCancelled ? FlagPayment::class : null;
    }
}
