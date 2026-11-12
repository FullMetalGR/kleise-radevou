<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingApproved;
use App\Application\ExpireHolds;
use App\Domain\Enum\BookingStatus;
use App\Jobs\SendAutoApprovedNotice;

/**
 * The business hears about an approval nobody at the business made.
 *
 * The decision is the actor on the record. An owner, a manager or a scoped staff user pressing
 * Approve, through the admin or through a signed link, is the business deciding, and answering
 * their own click back to them is noise. The sweeper granting a request whose window closed is
 * different in kind: nobody read it, nobody clicked anything, and the booking now standing in
 * the diary is one the business has never seen. That is worth a message, and it is worth a key
 * of its own, so an owner who finds even this noisy can switch it off without losing the
 * requests themselves.
 *
 * **It asks for the sweep's own actor and not merely for "not a user".** The three other actor
 * shapes are not the same thing: a customer never approves, the engine acting on its own is the
 * reap and the reap never approves, and a second job that approved for some other reason would
 * not be the timeout policy this message names in words. A sentence on a message is true of the
 * case it is printed for, so the case is identified exactly, from the one class that publishes
 * it.
 *
 * **Where the grant landed is the record's "to"**: a grant may land awaiting_payment, and the
 * notice must not call that booking confirmed. The job is told which, because the row it
 * re-reads may have moved on by the time it runs.
 *
 * **It runs after the commit and it does not throw**, and it runs whether or not the customer's
 * answer on the same record did. Both promises are QueuedMessages' for the hand-over; what
 * precedes it is the comparison of two values the record and ExpireHolds hold, which cannot
 * fail. What happens here is the decision and the handover; the recipient rule and the sending
 * happen in the job, after a re-read, because who at the business is party to a booking is a
 * question for the moment the message goes out.
 */
final readonly class AnnounceAutoApproval
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingApproved $approved): void
    {
        // The actor is a value and not an enum, so the comparison is of what it holds. Asking
        // ExpireHolds for it rather than spelling "job:sweeper" here keeps one spelling of the
        // name the trail, the events and this message all turn on.
        if ($approved->actor->value !== ExpireHolds::actor()->value) {
            return;
        }

        $this->messages->hand(
            SendAutoApprovedNotice::class,
            $approved->uuid,
            function (int $tenant) use ($approved): void {
                SendAutoApprovedNotice::dispatch($tenant, $approved->uuid, $approved->to === BookingStatus::AwaitingPayment);
            },
        );
    }
}
