<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Payment\PaymentNote;
use App\Application\Payment\PaymentNotFlagged;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The note on a payment that bought nothing the booking still holds, never a refund. Two roads
 * queue it: a cancel of a booking no longer held, after it commits, whoever cancelled (a held
 * booking carries no payment, and its cancel has the session it may carry expired instead,
 * ExpireCheckout); and the payment webhook, after the write that marks a late payment's event.
 *
 * **On the cancel's road it re-reads before it asks**. The note goes only where a payment
 * landed, read as the paid transition's audit row beside the payment_ref it recorded, and not
 * where a reference alone stands: an on-site settlement at the hop leaves the session an earlier
 * press opened, and nobody paid at it. The payment named is the one the paid transition
 * recorded. **On the late payment's road** the webhook has decided already: the payment named is
 * the one its notice named, and the note says the payment bought nothing. Whether the provider
 * is asked at all is its descriptor's supportsRefundFlag, read by the tenant's provider; the
 * note lands only there, and on the late road only where the notice named a payment and the
 * delivery marked the event.
 *
 * **Nothing below the job leaves it.** Every read and the provider call are inside one catch,
 * the house pattern of the Send jobs: a throw anywhere, a read's or the provider's, is reported
 * once as PaymentNotFlagged, which carries the booking and the class thrown and nothing it said,
 * and the job ends. So no stringified trace reaches failed_jobs, the sync driver's dispatch
 * never sees a throw to log as a job that could not be queued, the booking stands as it landed
 * and nothing retries the note. The late road is at most once: the webhook queues the job after
 * the write that marks the event, so a process that dies between the two loses the note, and
 * the provider's retry stops at the record (PaymentWebhook::receive). Queued in the clear: the
 * tenant's id, the booking's public identifier and, on the late road, the provider's id of the
 * payment, none of them a credential.
 */
final class FlagPayment implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $latePayment  the payment a late notice named, flagged as having bought nothing; null on the cancel's road, which reads the payment that landed itself
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $uuid,
        public readonly ?string $latePayment = null,
    ) {}

    public function handle(BookingReader $bookings, PaymentFactory $payments, ExceptionHandler $handler, LoggerInterface $log): void
    {
        try {
            $tenant = JobTenant::find($this->tenantId, self::class, $log);

            if (! $tenant instanceof Tenant) {
                return;
            }

            if ($this->latePayment !== null) {
                $paid = $this->latePayment;
                $note = PaymentNote::late($this->uuid);
            } else {
                $state = $payments->hop($tenant)->standing($this->uuid);
                $paid = $state !== null && $state->status === BookingStatus::Cancelled ? $state->paidRef : null;
                $note = PaymentNote::cancelled($this->uuid);
            }

            $booking = $paid === null ? null : $bookings->snapshot($tenant, $this->uuid);

            if ($paid === null || $booking === null) {
                return;
            }

            $payments->provider($tenant)->flagPayment($booking, $paid, $note);
        } catch (Throwable $failure) {
            $handler->report(PaymentNotFlagged::for($this->uuid, $failure));
        }
    }
}
