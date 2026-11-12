<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Booking\StaffBlock;
use App\Domain\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One item of a planned hold, everything the row stores decided: the staff member the planner
 * assigned, the service, the customer-visible range, the contended range the member is
 * occupied for, when the processing gap that follows ends if one does, and the price in the
 * service's currency. Nothing here is computed from a service or a mask; the planner did that,
 * and the protocol re-validates the ranges as given under the lock.
 *
 * The contended range is carried explicitly rather than as buffers, because the planner may
 * move a boundary: two consecutive segments on one member whose cleanup and setup buffers
 * would overlap meet at the later segment's start, so the stored range is not always the
 * customer range widened by the service's buffers. It always contains the customer range.
 */
final readonly class HoldItem
{
    public function __construct(
        public int $staffId,
        public int $serviceId,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public DateTimeImmutable $blockStart,
        public DateTimeImmutable $blockEnd,
        public ?DateTimeImmutable $processingEndsAt,
        public Money $price,
    ) {
        if ($staffId < 1) {
            throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
        }

        if ($serviceId < 1) {
            throw new InvalidArgumentException(sprintf('A service id is a positive integer, not %d.', $serviceId));
        }

        if ($end <= $start) {
            throw new InvalidArgumentException('An item ends after it starts.');
        }

        if ($blockStart > $start || $blockEnd < $end) {
            throw new InvalidArgumentException('An item\'s block range contains its customer range.');
        }

        if ($processingEndsAt !== null && $processingEndsAt <= $end) {
            throw new InvalidArgumentException('A processing gap ends after the item does.');
        }

        if ($price->minor < 0) {
            throw new InvalidArgumentException(sprintf('A price is zero or more minor units, not %d.', $price->minor));
        }
    }

    /**
     * The contended range on the member, as the row stores it and the overlap check reads it.
     */
    public function block(): StaffBlock
    {
        return new StaffBlock($this->staffId, $this->blockStart, $this->blockEnd);
    }
}
