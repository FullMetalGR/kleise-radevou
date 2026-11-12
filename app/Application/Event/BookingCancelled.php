<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * A booking was cancelled and the commit has happened: the slot is free again and whoever was
 * expecting to be there is not.
 *
 * It is its own record rather than a flag on a released hold, because the two are different
 * acts to everyone downstream. A cancel is news: the customer is told, the owner is told, and a
 * payment may be flagged for a refund. A customer handing a checkout hold back mid-flow is not
 * news at all, and that is BookingReleased.
 *
 * The status it came from is carried because a cancel reaches four of them, and the hold class
 * because the row can no longer say: cancelling nulls it.
 */
final readonly class BookingCancelled implements TransitionEvent
{
    use CarriesTheTransition;
}
