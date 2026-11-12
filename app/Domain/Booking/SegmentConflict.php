<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * The re-validation under lock refused one segment on capacity: it found an item whose block
 * range overlaps a blocking item of the same staff member. The whole booking is refused: the
 * transaction rolls back with nothing written, and the segment named here is what the API
 * answers 409 overlap with. Not a driver error, so the runner neither retries nor maps it; the
 * availability the client saw was advisory and this is the normal answer when it has gone.
 *
 * One thing and one code. A member outside their hours or on a blackout is not this: nobody
 * else holds the range, so it is ChainUnavailable, which is what the customer is told. The two
 * were one type once, and a blackout that appeared between the advisory read and the lock was
 * then reported to the customer as somebody else's booking.
 */
final class SegmentConflict extends RuntimeException
{
    private function __construct(
        public readonly int $segment,
        public readonly StaffBlock $block,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  int  $segment  zero-based, the item's position in the request
     */
    public static function at(int $segment, StaffBlock $block): self
    {
        if ($segment < 0) {
            throw new InvalidArgumentException(sprintf('A segment index is zero-based and never negative, not %d.', $segment));
        }

        $utc = new DateTimeZone('UTC');

        return new self($segment, $block, sprintf(
            'Segment %d overlaps a blocking item of staff member %d between %s and %s UTC.',
            $segment,
            $block->staffId,
            $block->start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $block->end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ));
    }
}
