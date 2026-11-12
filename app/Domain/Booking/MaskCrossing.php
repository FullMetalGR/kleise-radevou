<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * One segment of a placed chain whose service range crosses the open half of its member's day:
 * which segment, by its position in the chain, which member, and what it crosses. Read off the
 * masks rebuilt under the lock, so it is the rows' answer and not the request's.
 */
final readonly class MaskCrossing
{
    public function __construct(
        public int $segment,
        public int $staffId,
        public Overridable $kind,
    ) {
        if ($segment < 0 || $staffId < 1) {
            throw new InvalidArgumentException(sprintf('A crossing names a segment from zero and a staff id from one, not %d and %d.', $segment, $staffId));
        }
    }
}
