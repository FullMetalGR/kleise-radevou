<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingHeld;
use App\Application\Notification\NagTimes;
use App\Domain\Enum\HoldClass;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\SqlText;
use App\Jobs\SendApprovalNag;
use App\Tenancy\CurrentTenant;

/**
 * A request lodged with a human is nagged about three times while it waits: at a quarter, a half
 * and three quarters of its window.
 *
 * **Whether to nag is the record's hold class**, the way the request and the acknowledgement
 * decide: an approval hold is a customer waiting on somebody at the business, a checkout hold
 * is a customer still in the flow with nobody to ask, and an admin's booking has no hold at
 * all. **When to nag is the row's window**, read after the commit: the record carries no
 * expiry, and the insert wrote both ends of the window from one now(),
 * BookingReader::approvalWindow, so the three instants are quarters of the database's own span
 * and no clock of this application is in them. A row that is no longer on an approval hold by
 * the time this reads it, which a decision landing between the commit and this read would
 * leave, has no window to measure and is nagged about not at all: the class said nag, and the
 * row said there is nothing left to time it against.
 *
 * **Three jobs and not one that re-queues itself**, because the three are independent: a request
 * decided after the first nag gets no second and no third, and that is each job's own re-read to
 * find out, SendApprovalNag. Each is delayed to its instant and not by a number of seconds, for
 * the reason the reminder gives: an instant is what the queue stores and is the same on every
 * clock, where seconds would be the application's now added to the queue's now. Each carries
 * the expiry it was timed against, so a job that fires can hold the row to the state it was
 * queued for.
 *
 * **Nothing here dequeues a nag.** The re-read is the whole of the cancellation, as it is for
 * the reminder.
 *
 * **It runs after the commit and it does not throw**, and the other listeners on the same
 * record run whether this one worked or not. Both promises are QueuedMessages', and everything
 * that can fail here, the read included, happens inside its guard.
 */
final readonly class NagTheBusiness
{
    public function __construct(
        private QueuedMessages $messages,
        private CurrentTenant $current,
        private BookingReader $bookings,
    ) {}

    public function handle(BookingHeld $held): void
    {
        if ($held->holdClass !== HoldClass::Approval) {
            return;
        }

        $this->messages->hand(
            SendApprovalNag::class,
            $held->uuid,
            function (int $tenantId) use ($held): void {
                // The row behind the id the guard hands in: the publisher's binding.
                $window = $this->bookings->approvalWindow($this->current->get(), $held->uuid);

                if ($window === null) {
                    return;
                }

                $expiresAt = SqlText::instant($window->until);

                foreach (NagTimes::of($window) as $at) {
                    SendApprovalNag::dispatch($tenantId, $held->uuid, $expiresAt)->delay($at);
                }
            },
        );
    }
}
