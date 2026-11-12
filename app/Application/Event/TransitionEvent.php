<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * The marker every announced record carries, and the only thing a publisher is asked to take.
 *
 * It declares nothing. Each event is a record of its own, named for what it announces, so a
 * listener subscribes to the one record it cares about rather than to a general event it has to
 * branch on; the marker is what lets the port take all of them without knowing any.
 *
 * A record is not always a move. Most announce a transition a booking committed; one,
 * BookingPaidLate, announces that a payment landed on a booking it did not confirm, and its from
 * and to are the same status. The name is the one the transitions gave it; what binds the
 * records is that each is announced after a committed write, never what that write did.
 *
 * A record's own name and this one are the two a listener may subscribe to, and both work. The
 * dispatcher resolves a listener by the event's class and by the interfaces it implements, and
 * never by a parent, so a shared parent class would be a third name: one that matched nothing
 * and said nothing about it. There is none. The records that carry the same five facts take
 * them from a trait, CarriesTheTransition, and each implements this interface on its own.
 *
 * A listener that wants more than the record carries re-reads the booking, which it must do
 * anyway: the event is published after the commit and the row may have moved on by the time the
 * queue reaches it.
 */
interface TransitionEvent {}
