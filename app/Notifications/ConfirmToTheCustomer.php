<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingConfirmed;
use App\Domain\Enum\BookingStatus;
use App\Jobs\SendConfirmation;

/**
 * Every confirmed booking is confirmed to the customer, whatever it was confirmed from.
 *
 * There is no hold class to consult here and deliberately so. A checkout hold the customer
 * settled, an approval hold an owner said yes to, and an admin's booking with no hold at all
 * are three routes to one fact: the slot is the customer's. The rule that asks about the hold
 * class is about acknowledgements, where the question is whether the customer is still in the
 * flow; by the time anything is confirmed that question is answered.
 *
 * **It runs after the commit and it does not throw**, and the business's notice on the same
 * record runs whether this one worked or not. Both promises are QueuedMessages', which is where
 * the tenant is read and where a failure becomes a logged line instead of a throw that would
 * stop the dispatcher before the second listener. The message itself is sent from the job,
 * because the plaintext manage token is on the record and a job is where it can travel
 * encrypted; see SendConfirmation.
 */
final readonly class ConfirmToTheCustomer
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingConfirmed $confirmed): void
    {
        $this->messages->hand(
            SendConfirmation::class,
            $confirmed->uuid,
            function (int $tenant) use ($confirmed): void {
                SendConfirmation::dispatch($tenant, $confirmed->uuid, $confirmed->manageToken, $confirmed->afterPayment, $confirmed->from === BookingStatus::AwaitingPayment);
            },
        );
    }
}
