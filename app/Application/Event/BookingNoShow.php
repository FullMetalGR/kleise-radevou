<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * An appointment did not happen and nobody told anybody: the customer did not arrive.
 *
 * Its own record beside BookingCompleted, because whatever is done about a customer who does
 * not turn up, a fee, a note on the customer or nothing at all, it is not what is done about
 * one who did.
 */
final readonly class BookingNoShow implements TransitionEvent
{
    use CarriesTheTransition;
}
