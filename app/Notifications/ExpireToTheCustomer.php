<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingExpired;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendRequestExpired;

/**
 * A lapsed hold is news to the customer only where the customer was waiting on somebody.
 *
 * The decision is the hold class the booking lapsed under, and it is made on the record because
 * the row can no longer say: an expiry nulls the class with the status.
 *
 * - **approval**: a customer who was told their request was with the business and that they
 *   would hear either way. Nobody decided in time, and this is the third of the three ways that
 *   promise is kept.
 * - **checkout**: a customer still in the booking flow who walked away from a page. Mailing
 *   there would mail every abandoned checkout.
 * - **payment**: an approved booking whose payment hold lapsed before the payment arrived. Where
 *   the tenant has the approved message on, that customer was told to pay and that the link
 *   would say if the time went back, and this tells them first; where it is off, this is their
 *   first word of the approval. Either way it is the expiry message's second form,
 *   RequestExpired::unpaid, under the same key.
 *
 * It answers both paths that end a hold and knows neither of them apart: the sweeper, and the
 * reap inside a later booking transaction, which lets go of whatever it finds on the staff-days
 * it locked.
 *
 * **It runs after the commit and it does not throw**: a hold that was let go stays let go
 * whatever the queue says. That promise is QueuedMessages'.
 */
final readonly class ExpireToTheCustomer
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingExpired $expired): void
    {
        if ($expired->holdClass !== HoldClass::Approval && $expired->holdClass !== HoldClass::Payment) {
            return;
        }

        $unpaid = $expired->holdClass === HoldClass::Payment;

        $this->messages->hand(
            SendRequestExpired::class,
            $expired->uuid,
            function (int $tenant) use ($expired, $unpaid): void {
                SendRequestExpired::dispatch($tenant, $expired->uuid, $unpaid);
            },
        );
    }
}
