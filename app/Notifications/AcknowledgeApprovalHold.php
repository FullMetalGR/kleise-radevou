<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingHeld;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendAcknowledgement;

/**
 * The acknowledgement rule, as a listener on the one event that can answer it.
 *
 * An approval hold gets a message and a checkout hold gets none. The customer on a checkout
 * hold is still in the booking flow and will have their answer within fifteen minutes, so
 * mailing there would mail every abandoned checkout; the customer on an approval hold is
 * waiting on a person, and the request itself goes to the approver, so without this they hear
 * nothing at all until somebody decides.
 *
 * The decision is made on the record's hold class and on nothing else. The row cannot be asked:
 * settling a booking nulls the class, and by the time a queue reaches the row the booking may
 * have moved on.
 *
 * **It runs after the commit and it does not throw**, and a listener that joins this record
 * later runs whether this one worked or not. Both promises are QueuedMessages', which is where
 * the tenant is read and where a failure becomes a logged line. The message itself is sent from
 * the job, because the plaintext manage token is on the record and a job is where it can travel
 * encrypted; see SendAcknowledgement.
 */
final readonly class AcknowledgeApprovalHold
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingHeld $held): void
    {
        if ($held->holdClass !== HoldClass::Approval) {
            return;
        }

        $this->messages->hand(
            SendAcknowledgement::class,
            $held->uuid,
            function (int $tenant) use ($held): void {
                SendAcknowledgement::dispatch($tenant, $held->uuid, $held->manageToken);
            },
        );
    }
}
