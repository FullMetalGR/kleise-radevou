<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;

/**
 * The five facts a record of the compare-and-set shape announces: which booking, where it came
 * from, where it landed, which hold it was under, and who acted.
 *
 * Eight records use it: seven transitions (approved, rejected, cancelled, released, expired,
 * completed, no-show) and one record that announces a non-move, BookingPaidLate, whose from and
 * to are the one status the payment webhook read and whose hold class is null, because a late
 * payment moves nothing and settles no hold. A record built from this shape may therefore say
 * the booking did not move; a listener reads from and to as what they are, never as proof of a
 * move.
 *
 * The records were classes repeating one constructor word for word. They stay separate
 * classes, because the type is how a listener says which record it cares about; what they no
 * longer repeat is the shape, which was identical in every one of them and had to be read once
 * per class to be believed identical.
 *
 * It is a trait and deliberately not a base class. The dispatcher resolves a listener by the
 * event's own class and by the interfaces it implements, through class_implements, and never
 * walks its parents; a subscription written against a shared parent would therefore match
 * nothing and do it silently. A shape that cannot be subscribed to must not be a type that
 * looks as though it can, so there is no such type: the only names a listener can write are a
 * record and TransitionEvent, and both of them work. tests/Unit/TransitionEventTest holds the
 * records to that shape.
 *
 * The properties say readonly although every record using this is a readonly class, because a
 * readonly class may not use a trait whose properties are not: the modifier is what lets the
 * one shape be used by all eight.
 *
 * The hold class is on every record because the row can no longer say: settling a booking nulls
 * it, and the acknowledgement rule is decided by it. The status it came from is there because
 * most of these are reachable from more than one. Everything else a listener needs it re-reads
 * from the booking, which it must do anyway: the event is published after the commit and the row
 * may have moved on by the time the queue reaches it.
 *
 * A later event that carries something of its own is not obliged to use this: BookingHeld,
 * BookingConfirmed and BookingRescheduled do not. It is the shape eight records happen to share,
 * not a declaration that every event must.
 */
trait CarriesTheTransition
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?BookingStatus $from,
        public readonly BookingStatus $to,
        public readonly ?HoldClass $holdClass,
        public readonly Actor $actor,
    ) {}
}
