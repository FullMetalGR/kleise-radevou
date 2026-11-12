<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingCancelled;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendCancellation;

/**
 * Every cancelled booking is told to the customer, except the one that was never news.
 *
 * **It subscribes to the cancel and not to the release**, which is the reason they are two
 * records: a customer handing a checkout hold back mid-flow leaves the same cancelled row, and
 * only the record still knows which act it was. Nothing here has to tell them apart by reading
 * the booking, because BookingReleased never arrives.
 *
 * **A checkout hold gets no mail on any event**, and a cancel reaches one: the manage token a
 * hold answers with can cancel it before it is confirmed. That customer is still inside the
 * booking flow, and mailing them would mail every abandoned checkout that happened to be given
 * up through the route instead of the release. The class is read off the record because the row
 * nulls it.
 *
 * **What the words turn on is decided here and carried**, because the row has forgotten it:
 * whether the booking was still a request, which is the status it came from, and whether the
 * customer did it, which is the actor. Both are the record's and only the record's.
 *
 * **It runs after the commit and it does not throw**, and the business's notice on the same
 * record runs whether this one worked or not. Both promises are QueuedMessages'.
 */
final readonly class CancelToTheCustomer
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingCancelled $cancelled): void
    {
        if ($cancelled->holdClass === HoldClass::Checkout) {
            return;
        }

        $wasARequest = $cancelled->from !== null && $cancelled->from->awaitsADecision();
        $byTheCustomer = $cancelled->actor->isCustomer();

        $this->messages->hand(
            SendCancellation::class,
            $cancelled->uuid,
            function (int $tenant) use ($cancelled, $wasARequest, $byTheCustomer): void {
                SendCancellation::dispatch($tenant, $cancelled->uuid, $wasARequest, $byTheCustomer);
            },
        );
    }
}
