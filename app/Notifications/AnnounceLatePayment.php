<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingPaidLate;
use App\Jobs\SendLatePaymentNotice;

/**
 * The owner hears of a payment that landed for a booking it did not confirm: it bought
 * nothing, and refunding it is their act in their provider's dashboard.
 *
 * There is no condition on the record. The payment webhook announces it once per event, after
 * the write that marks the event and only where that write marked it, so a repeat delivery and
 * a concurrent one that did not mark announce nothing; and it is announced whether or not the
 * notice named a payment, since the owner's message is not the flag's. A cancelled booking's
 * flag, which is TellTheProvider's and no message, mails nobody.
 *
 * **It runs after the mark and it does not throw.** Both promises are QueuedMessages'. The
 * recipients, the owners alone, and the words are the job's, after a re-read.
 */
final readonly class AnnounceLatePayment
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingPaidLate $late): void
    {
        $this->messages->hand(
            SendLatePaymentNotice::class,
            $late->uuid,
            function (int $tenant) use ($late): void {
                SendLatePaymentNotice::dispatch($tenant, $late->uuid);
            },
        );
    }
}
