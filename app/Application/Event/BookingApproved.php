<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * The owner said yes to a request and the commit has happened: the customer who was waiting on
 * a human has their answer.
 *
 * It is its own record and not a confirmation, although both can land on confirmed. The two are
 * different acts with different audiences: a confirm is the customer settling their own hold
 * and tells the owner, an approval is the owner deciding and tells the customer. An approval
 * lands on awaiting_payment where a booking has to be paid for, and a listener written against
 * the status would then be announcing the wrong thing entirely.
 *
 * The sweeper publishes this one too, with the job as its actor, because a request granted by a
 * policy the owner set is the owner having said yes in advance.
 */
final readonly class BookingApproved implements TransitionEvent
{
    use CarriesTheTransition;
}
