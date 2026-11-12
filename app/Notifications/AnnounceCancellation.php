<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingCancelled;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendCancellationNotice;

/**
 * The business hears about every cancelled booking somebody there was expecting, whoever
 * cancelled it.
 *
 * There is no condition on the actor, and deliberately not. A user's own cancellation is
 * still news to the rest of the tenant: the one who clicked is not the only one who was
 * expecting the customer, and the notice names who it was rather than staying silent for the
 * one person who already knows. That is the difference from the approval, where the decision
 * is the business's own and answering it back to them is noise.
 *
 * **A checkout hold gets no mail on any event**, and that holds for the business too: nobody
 * there heard of a checkout hold, since the new-booking notice goes on the confirm, so a hold
 * cancelled before it was confirmed is a booking they never had. The class is read off the
 * record because the row nulls it. The release, which is the ordinary way a checkout hold ends
 * early, is a record this never subscribes to.
 *
 * **It runs after the commit and it does not throw**, and it runs whether or not the customer's
 * message on the same record did. Both promises are QueuedMessages'. What happens here is the
 * decision and the handover; the recipient rule, the actor's name and the sending happen in the
 * job, after a re-read, because who at the business is party to a booking and what a user is
 * called are questions for the moment the message goes out.
 */
final readonly class AnnounceCancellation
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingCancelled $cancelled): void
    {
        if ($cancelled->holdClass === HoldClass::Checkout) {
            return;
        }

        $wasARequest = $cancelled->from !== null && $cancelled->from->awaitsADecision();

        $this->messages->hand(
            SendCancellationNotice::class,
            $cancelled->uuid,
            function (int $tenant) use ($cancelled, $wasARequest): void {
                SendCancellationNotice::dispatch($tenant, $cancelled->uuid, $wasARequest, $cancelled->actor->value);
            },
        );
    }
}
