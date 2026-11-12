<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * One segment's service limits for the customer: how many hours before the booking's first
 * start a cancel and a reschedule stop being allowed. A chain carries one of these per segment
 * and the policy takes the largest of each, so the strictest service in the chain sets the
 * limit for the whole booking.
 */
final readonly class ServiceWindows
{
    public function __construct(
        public int $cancelHours,
        public int $rescheduleHours,
    ) {
        if ($cancelHours < 0 || $rescheduleHours < 0) {
            throw new InvalidArgumentException(sprintf(
                'A policy window is a count of hours and never negative, not %d for cancel and %d for reschedule.',
                $cancelHours,
                $rescheduleHours,
            ));
        }
    }
}
