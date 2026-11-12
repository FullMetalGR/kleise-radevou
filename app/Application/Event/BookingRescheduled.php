<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use DateTimeImmutable;

/**
 * A booking was moved to another time and the commit has happened: the old range is back in the
 * diary, the new one is taken, and whoever was expecting the customer at the old time is not.
 *
 * It is the one committed transition whose record does not carry the five facts the others
 * share, and deliberately. A move leaves the status where it found it, so a from and a to would
 * be the same value written twice and the record would say nothing at all about what happened.
 * What happened is the two instants, and only one of them survives the commit: the start the
 * booking took can be re-read from the row, and the start it left cannot be read from anywhere
 * once the old items are gone. A listener that has to say "your appointment has moved from
 * Tuesday" needs it carried, and the trait is the shape nine transitions happen to share rather
 * than a declaration that every event must.
 *
 * It implements the marker on its own, like every other record, so a listener can subscribe to
 * this class or to TransitionEvent and both work. There is no parent here either: the dispatcher
 * never walks one, so a shared base would be a name a listener could write and a subscription
 * that silently matched nothing.
 *
 * The status is carried once because the move did not change it and a listener still needs to
 * know whether it is announcing a confirmed appointment or a request still in front of the owner.
 * The hold class rides beside it for the reason it rides on the others: the row's own column is
 * nulled the moment the booking settles, and the acknowledgement rule is decided by it.
 *
 * The session rides where the move cleared one: a booking still waiting for its payment that
 * carried a session has that session's reference cleared under the lock, and the row can no
 * longer say which it was, so TellTheProvider reads it here to have the provider expire it after
 * the commit.
 *
 * Whether the customer is told rides as the mover chose it: true for every move by the customer
 * and by default for the business, false where the business moved it and left the customer
 * untold. The listener decides on it; the record does not send anything.
 */
final readonly class BookingRescheduled implements TransitionEvent
{
    public function __construct(
        public string $uuid,
        public BookingStatus $status,
        public ?HoldClass $holdClass,
        public Actor $actor,
        public DateTimeImmutable $movedFrom,
        public DateTimeImmutable $movedTo,
        public ?string $session = null,
        public bool $tellCustomer = true,
    ) {}
}
