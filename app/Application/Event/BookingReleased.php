<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * A customer handed their checkout hold back and the commit has happened: they left the booking
 * flow, or asked for another time, and the slot is free again early.
 *
 * The row it leaves is cancelled, the same status a cancel leaves, which is why this is a
 * record of its own: the notifications subscribe by type, and a checkout hold gets no mail on
 * any event. A listener that had to tell the two apart by reading the booking could not: both
 * are cancelled by then, and the class that would have said which is nulled with them.
 * Announcing the act rather than the state is what keeps every abandoned checkout out of the
 * customer's inbox.
 */
final readonly class BookingReleased implements TransitionEvent
{
    use CarriesTheTransition;
}
