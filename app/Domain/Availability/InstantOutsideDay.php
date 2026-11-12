<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A conversion to a slot index was asked for an instant the staff-day does not contain. The
 * side it fell on is named rather than the index clamped, so a range that leaves the day is
 * clipped by whoever knows what the range means, never silently shortened here.
 */
final class InstantOutsideDay extends InvalidArgumentException
{
    private function __construct(
        public readonly DateTimeImmutable $instant,
        public readonly string $day,
        public readonly DaySide $side,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function on(StaffDayGeometry $geometry, DateTimeImmutable $instant, DaySide $side): self
    {
        return new self($instant, $geometry->day, $side, sprintf(
            '%s falls %s the day %s in %s.',
            $instant->setTimezone($geometry->zone)->format(DateTimeImmutable::RFC3339),
            $side === DaySide::Before ? 'before' : 'after',
            $geometry->day,
            $geometry->zone->getName(),
        ));
    }
}
