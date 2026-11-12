<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * An appointment happened and the owner said so.
 *
 * It is the end of a booking's life rather than a change of plan, which is why it is its own
 * record beside BookingNoShow: the two land on different statuses, they are the answer to the
 * same question, and what a listener does with them could not differ more.
 */
final readonly class BookingCompleted implements TransitionEvent
{
    use CarriesTheTransition;
}
