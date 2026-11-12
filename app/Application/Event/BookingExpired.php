<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * A hold lapsed and its slot was released: written by the reap of the write protocol's step 4,
 * and by the sweeper when it lands.
 *
 * The class it held is what decides whether the customer hears about it: an abandoned checkout
 * lapsing is not news, a timed-out approval request is the customer's answer.
 */
final readonly class BookingExpired implements TransitionEvent
{
    use CarriesTheTransition;
}
