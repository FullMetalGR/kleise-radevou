<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One staff member's block range: the contended range of one booking item, as instants. The
 * range is half-open, the start inside it and the end outside, which is why a range ending
 * exactly at midnight touches one day and not two. The instants may carry any zone; only their
 * position on the timeline is read.
 */
final readonly class StaffBlock
{
    public function __construct(
        public int $staffId,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($staffId < 1) {
            throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
        }

        if ($end <= $start) {
            throw new InvalidArgumentException(sprintf(
                'A block range ends after it starts; %s to %s does not.',
                $start->format(DateTimeImmutable::RFC3339_EXTENDED),
                $end->format(DateTimeImmutable::RFC3339_EXTENDED),
            ));
        }
    }
}
