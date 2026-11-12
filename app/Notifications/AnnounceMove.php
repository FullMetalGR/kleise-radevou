<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingRescheduled;
use App\Domain\Audit\AuditEntry;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendMoveNotice;
use DateTimeZone;

/**
 * The business hears about every moved booking somebody there was expecting.
 *
 * **A checkout hold gets no mail on any event**, and that holds for the business as it does for
 * the customer: nobody there heard of a checkout hold, since the new-booking notice goes on the
 * confirm, so a hold moved before it was confirmed is a booking they never had. The class is
 * read off the record because the row nulls it once the booking settles.
 *
 * **The start it left goes with the job as the trail spells it**, AuditEntry::INSTANT, for the
 * reason MoveToTheCustomer gives; whether it was still a request is the status the record
 * carries, and who moved it is the actor as the trail spells one, turned into a name in the job
 * where the tenant's users may be read. The business moves bookings too, and the rule is the
 * cancellation's, AnnounceCancellation: everybody at the business the recipient rule names is
 * told, the user who moved it included, whatever the customer was told, since a user's own act
 * is still news to whoever was expecting the customer.
 *
 * **It runs after the commit and it does not throw**, and it runs whether or not the customer's
 * message on the same record did. Both promises are QueuedMessages'.
 */
final readonly class AnnounceMove
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingRescheduled $moved): void
    {
        if ($moved->holdClass === HoldClass::Checkout) {
            return;
        }

        $wasARequest = $moved->status->awaitsADecision();
        $movedFrom = $moved->movedFrom->setTimezone(new DateTimeZone('UTC'))->format(AuditEntry::INSTANT);

        $this->messages->hand(
            SendMoveNotice::class,
            $moved->uuid,
            function (int $tenant) use ($moved, $wasARequest, $movedFrom): void {
                SendMoveNotice::dispatch($tenant, $moved->uuid, $wasARequest, $moved->actor->value, $movedFrom);
            },
        );
    }
}
