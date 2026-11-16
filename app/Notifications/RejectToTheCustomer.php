<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingRejected;
use App\Jobs\SendRequestRejected;

/**
 * Every declined request is answered to the customer.
 *
 * There is no condition on the record: a rejection is reachable from awaiting_approval and from
 * nowhere else, so every one of these is a request somebody was told to wait for. It is the
 * second of the three answers the acknowledgement promised.
 *
 * Nobody at the business is told. A rejection is the business's own click, and answering their
 * own decision back to them is noise; the reason they may have typed with it is theirs and
 * stays on the row.
 *
 * A request rejected because the business added a closure over it is answered in the closure's
 * words in place of these, which ClosureCauses names for the length of that rejection; the job
 * carries the cause.
 *
 * **It runs after the commit and it does not throw**: the transition is over, there is nothing
 * to roll back, and a message that could not be queued is a fault to log. That promise is
 * QueuedMessages'.
 */
final readonly class RejectToTheCustomer
{
    public function __construct(private QueuedMessages $messages, private ClosureCauses $causes) {}

    public function handle(BookingRejected $rejected): void
    {
        $closure = $this->causes->of($rejected->uuid);

        $this->messages->hand(
            SendRequestRejected::class,
            $rejected->uuid,
            function (int $tenant) use ($rejected, $closure): void {
                SendRequestRejected::dispatch($tenant, $rejected->uuid, $closure);
            },
        );
    }
}
