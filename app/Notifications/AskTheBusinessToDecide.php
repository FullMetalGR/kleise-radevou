<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingHeld;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendApprovalRequest;

/**
 * A request lodged with a human has to reach the human, as a listener on the one event that can
 * say whether it was.
 *
 * The decision is the record's hold class and nothing else: an approval hold is a customer
 * waiting on somebody at the business, and this is what tells them there is something waiting.
 * A checkout hold is a customer still in the booking flow with nobody to ask, and an admin's
 * booking has no hold at all. The row cannot be asked either way, because settling a booking
 * nulls the class and a queue may reach the row after somebody has.
 *
 * It is the other half of the acknowledgement: the customer is told their request is with the
 * business, and this is the business being told. The two listeners run on one record and
 * neither knows the other exists.
 *
 * **It runs after the commit and it does not throw**, and the acknowledgement on the same
 * record runs whether this one worked or not. Both promises are QueuedMessages'. The message
 * itself is sent from the job, because who at the business is party to a booking is a question
 * for the moment the message goes out, and because the links it carries are credentials that
 * are minted where they are sent; see SendApprovalRequest.
 */
final readonly class AskTheBusinessToDecide
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingHeld $held): void
    {
        if ($held->holdClass !== HoldClass::Approval) {
            return;
        }

        $this->messages->hand(
            SendApprovalRequest::class,
            $held->uuid,
            function (int $tenant) use ($held): void {
                SendApprovalRequest::dispatch($tenant, $held->uuid);
            },
        );
    }
}
