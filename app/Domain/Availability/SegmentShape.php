<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * The span one segment takes on a staff member's timeline, in minutes as the service row spells
 * it: the duration the customer is served for, and the setup and cleanup buffers on either side
 * of it. All three occupy the staff member and contend with other bookings; only the duration
 * has to fall inside opening hours.
 *
 * There is no processing gap here. A gap is the pause between two segments of a chain, during
 * which the staff member is free, so it belongs to the chain and not to the segment: a segment
 * booked alone blocks nothing after its cleanup buffer ends. Nothing is rounded here either;
 * minutes become slots only against a granularity, and the window knows which one.
 */
final readonly class SegmentShape
{
    public function __construct(
        public int $durationMin,
        public int $bufferBeforeMin = 0,
        public int $bufferAfterMin = 0,
    ) {
        if ($durationMin < 1) {
            throw new InvalidArgumentException(sprintf('A segment lasts at least one minute, not %d.', $durationMin));
        }

        foreach ([$bufferBeforeMin, $bufferAfterMin] as $buffer) {
            if ($buffer < 0) {
                throw new InvalidArgumentException(sprintf('A buffer is a number of minutes at or above zero, not %d.', $buffer));
            }
        }
    }
}
