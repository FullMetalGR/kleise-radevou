<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Why the business cancelled or rejected a booking, when it did so because it added a closure
 * over the booking's time: the closure's reason as the business typed it, null where none was.
 * The customer's message rides on it from the listener to the job, so it is a plain value the
 * queue can carry.
 */
final readonly class ClosureCause
{
    public function __construct(public ?string $reason) {}
}
