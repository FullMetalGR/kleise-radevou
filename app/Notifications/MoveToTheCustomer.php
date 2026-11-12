<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Event\BookingRescheduled;
use App\Domain\Audit\AuditEntry;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Jobs\SendMove;
use DateTimeZone;

/**
 * Every moved booking is told to the customer, except the one that was never news and the one
 * the business moved and chose not to tell them about.
 *
 * **A move the business left untold sends the customer nothing**: the confirmation's "Tell the
 * customer" box, ticked by default, rides the record, and an unticked one is the business's word
 * that the customer hears of it some other way or not at all. The business's own notice is not this
 * listener's and goes either way.
 *
 * **A checkout hold gets no mail on any event**, and a move reaches one: a live pending hold may be
 * moved through its manage token before it is confirmed. That customer is still inside the booking
 * flow. The class is read off the record because the row nulls it once the booking settles.
 *
 * **The start it left goes with the job, spelled as the trail spells it.** The record carries
 * it because the old items are gone and nothing else does, BookingRescheduled; it is written
 * out as UTC text with its offset, AuditEntry::INSTANT, and read back naming its zone, so the
 * instant crosses the queue the way an instant crosses a row and never as a bound date object.
 * The start it took is not carried: the job re-reads it, and the message then agrees with the
 * items and the file it prints beside it.
 *
 * **What the words turn on is decided here**: whether the booking was still a request and whether
 * it was approved and waiting to be paid, which are the status the record carries, and whether the
 * customer did it, which is the actor: a signed-in user or support moving it is the business, and
 * the message says the business moved it and who to ask. The second carries no calendar file, and
 * says the booking still awaits its payment only if the row re-read at send time still does. A move
 * leaves the status where it found it, so the row could still say either; it is read off the record
 * all the same, because the row at send time may have been decided or paid since and the message
 * announces the move that happened.
 *
 * **It runs after the commit and it does not throw**, and the business's notice on the same
 * record runs whether this one worked or not. Both promises are QueuedMessages'.
 */
final readonly class MoveToTheCustomer
{
    public function __construct(private QueuedMessages $messages) {}

    public function handle(BookingRescheduled $moved): void
    {
        if ($moved->holdClass === HoldClass::Checkout || ! $moved->tellCustomer) {
            return;
        }

        $wasARequest = $moved->status->awaitsADecision();
        $awaitingPayment = $moved->status === BookingStatus::AwaitingPayment;
        $byTheCustomer = $moved->actor->isCustomer();
        $movedFrom = $moved->movedFrom->setTimezone(new DateTimeZone('UTC'))->format(AuditEntry::INSTANT);

        $this->messages->hand(
            SendMove::class,
            $moved->uuid,
            function (int $tenant) use ($moved, $wasARequest, $byTheCustomer, $movedFrom, $awaitingPayment): void {
                SendMove::dispatch($tenant, $moved->uuid, $wasARequest, $byTheCustomer, $movedFrom, $awaitingPayment);
            },
        );
    }
}
