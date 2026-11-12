<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * The port a use case announces a committed transition through.
 *
 * It is called after the repository's transaction has returned, which is after the commit by
 * construction, and never from inside the closure: nothing inside the transaction may talk to
 * a network. It never throws, because by the time it runs the booking is already written and
 * there is nothing left to roll back; a listener that fails is a fault to log, not a failed
 * booking.
 */
interface TransitionPublisher
{
    public function publish(TransitionEvent $event): void;
}
