<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingCompleted;
use App\Jobs\SendThankYou;

/**
 * Every booking the business marks completed thanks its customer.
 *
 * Nothing is read off the record but the booking: a booking is completed from confirmed, with no
 * hold, by a signed-in user, and the message is the same whoever marked it. The job re-reads the
 * row and sends only while it is still completed.
 *
 * **It runs after the commit and it does not throw**, both promises QueuedMessages'.
 */
final readonly class ThankTheCustomer
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingCompleted $completed): void
    {
        $this->messages->hand(
            SendThankYou::class,
            $completed->uuid,
            function (int $tenant) use ($completed): void {
                SendThankYou::dispatch($tenant, $completed->uuid);
            },
        );
    }
}
