<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingApproved;
use App\Domain\Enum\BookingStatus;
use App\Jobs\SendRequestApproved;

/**
 * Every approved request is answered to the customer, whoever or whatever approved it.
 *
 * There is no condition on the record here and deliberately so. An owner pressing Approve and
 * the sweeper granting a request the owner's own policy said yes to in advance are one fact for
 * the person who has been waiting: they asked, and the answer is yes. Who decided changes what
 * the business is told, which is AnnounceAutoApproval's question and not this one's.
 *
 * Where the approval landed is this one's question, read from the record's "to": an approval
 * that landed awaiting_payment is answered with the payment link and no calendar file, under
 * the same key.
 *
 * It answers the acknowledgement. The customer was told their request was with the business and
 * that they would hear either way; this is one of the three ways that promise is kept, and the
 * other two are on the rejection and the timeout.
 *
 * **It runs after the commit and it does not throw**, and the business's notice on the same
 * record runs whether this one worked or not. Both promises are QueuedMessages', which is where
 * the tenant is read and where a failure becomes a logged line instead of a throw that would
 * stop the dispatcher before the second listener.
 */
final readonly class ApproveToTheCustomer
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingApproved $approved): void
    {
        $this->messages->hand(
            SendRequestApproved::class,
            $approved->uuid,
            function (int $tenant) use ($approved): void {
                SendRequestApproved::dispatch($tenant, $approved->uuid, $approved->to === BookingStatus::AwaitingPayment);
            },
        );
    }
}
