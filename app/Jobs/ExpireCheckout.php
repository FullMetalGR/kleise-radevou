<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Payment\CheckoutNotExpired;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The expiry of a checkout session that outlived its hold: a hold that ended unpaid carrying a
 * session, by a lapse the sweeper or a reap wrote, a release, a cancel or the on-site settlement
 * at the hop, has that session ended at the provider, so the payment page dies with the hold
 * rather than at the session's own end. TellTheProvider queues it on the record of a transition
 * that ended a checkout or a payment hold, after the commit; the booking app's confirm queues
 * none, and TellTheProvider says why.
 *
 * **It re-reads before it asks**. The provider is asked only where the booking is no longer
 * held, a session is recorded for it (payment_ref), and no payment landed for it, read as the
 * paid transition's audit row (CheckoutState's paid): a booking a payment confirmed is never
 * asked to expire anything, so the expiry and the flag never both apply to one booking from one
 * transition. A session no row records cannot be named and ends at the provider's own end.
 * Whether the provider is asked at all is its descriptor's canExpireCheckout, read by the
 * tenant's provider, which answers as the null provider for a connection that no longer works.
 *
 * **A move names its session on the job**: a move of a booking still waiting for its payment
 * cleared payment_ref under its lock, so the row no longer names the session the pay hop
 * recorded, and the booking is still held. That session is expired unless the booking is gone or
 * the row names that session again, as the reference a payment landing at it wrote, so a session
 * a payment completed is never asked to expire; a session the next visit of the pay hop opened
 * is another, and is left alone.
 *
 * **Nothing below the job leaves it**, the pattern of FlagPayment: every read and the provider
 * call are inside one catch, a throw anywhere is reported once as CheckoutNotExpired, which
 * carries the booking and the class thrown and nothing it said, and the job ends. The ended
 * hold stands, nothing reaches failed_jobs and nothing retries; a sweep that ended many holds
 * queued one job each, so one provider's throw stops nobody else's expiry. Queued in the clear:
 * the tenant's id, the booking's public identifier and a move's session reference, none of them
 * a credential, as the late payment's flag queues the payment's.
 */
final class ExpireCheckout implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $session  the session a move cleared from the row, to expire though the booking is still held; null reads the session off the row
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly ?string $session = null,
    ) {}

    public function handle(BookingReader $bookings, PaymentFactory $payments, ExceptionHandler $handler, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            $state = $payments->hop($tenant)->standing($this->uuid);
            $session = match (true) {
                $state === null => null,
                $this->session !== null => $state->paymentRef === $this->session ? null : $this->session,
                default => $state->paid || $state->status->isHeld() ? null : $state->paymentRef,
            };
            $booking = $session === null ? null : $bookings->snapshot($tenant, $this->uuid);

            if ($session === null || $booking === null) {
                return;
            }

            $payments->provider($tenant)->expireCheckout($booking, $session);
        } catch (Throwable $failure) {
            $handler->report(CheckoutNotExpired::for($this->uuid, $failure));
        }
    }
}
