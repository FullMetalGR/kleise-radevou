<?php

declare(strict_types=1);

namespace App\Domain\Chain;

use App\Domain\Availability\SegmentShape;
use InvalidArgumentException;

/**
 * One segment of a chain as the availability query and the hold describe it: the service's
 * shape, the processing gap that follows it, the staff members eligible to perform it, the
 * customer's pick among them or none, and the service's lead time and horizon. Everything comes
 * from the service row, the service_staff rows and the request; nothing here is read from a
 * clock or a mask.
 *
 * The gap sits here and not on the shape, because it belongs to the chain: it is the pause
 * before the next segment, during which the staff member is free, and a segment booked alone
 * blocks nothing after its cleanup buffer ends. The lead time and horizon sit here because the
 * chain takes the largest lead time and the smallest horizon among its segments, so each segment
 * must carry its own.
 *
 * The eligible members are kept ascending so that every walk over them is deterministic, and a
 * member named twice or a pick outside the eligible members is refused as a bug in whoever built
 * the segment rather than absorbed.
 */
final readonly class ChainSegment
{
    /**
     * @var list<int> ascending
     */
    public array $eligibleStaffIds;

    /**
     * @param  list<int>  $eligibleStaffIds  the staff members assigned to the service, in any order
     * @param  int|null  $pickedStaffId  the customer's pick, one of the eligible members, or null for no preference
     */
    public function __construct(
        public SegmentShape $shape,
        public int $processingGapMin,
        array $eligibleStaffIds,
        public ?int $pickedStaffId,
        public int $leadTimeMin,
        public int $horizonDays,
    ) {
        if ($processingGapMin < 0) {
            throw new InvalidArgumentException(sprintf('A processing gap is a number of minutes at or above zero, not %d.', $processingGapMin));
        }

        if ($leadTimeMin < 0) {
            throw new InvalidArgumentException(sprintf('A lead time is a number of minutes at or above zero, not %d.', $leadTimeMin));
        }

        if ($horizonDays < 0) {
            throw new InvalidArgumentException(sprintf('A horizon is a number of days at or above zero, not %d.', $horizonDays));
        }

        if ($eligibleStaffIds === []) {
            throw new InvalidArgumentException('A segment names at least one eligible staff member.');
        }

        $seen = [];

        foreach ($eligibleStaffIds as $staffId) {
            if ($staffId < 1) {
                throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
            }

            if (isset($seen[$staffId])) {
                throw new InvalidArgumentException(sprintf('A segment names each eligible staff member once, not %d twice.', $staffId));
            }

            $seen[$staffId] = true;
        }

        if ($pickedStaffId !== null && ! isset($seen[$pickedStaffId])) {
            throw new InvalidArgumentException(sprintf('A pick names one of the segment\'s eligible staff members, not %d.', $pickedStaffId));
        }

        sort($eligibleStaffIds);

        $this->eligibleStaffIds = $eligibleStaffIds;
    }

    /**
     * The staff members the segment may be assigned to: the pick alone when the customer made
     * one, every eligible member otherwise. Ascending either way.
     *
     * @return non-empty-list<int>
     */
    public function candidates(): array
    {
        if ($this->pickedStaffId !== null) {
            return [$this->pickedStaffId];
        }

        return $this->eligibleStaffIds;
    }
}
