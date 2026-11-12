<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One booking item as a reader hands it to a caller: its place in the chain, the service and
 * the staff member by id and by name, the range the customer was sold, and the price the row
 * fixed at hold time.
 *
 * The staff member's name travels beside the flag saying whether that member is the tenant's
 * implicit one, the row standing for the business itself. Whether a name is shown is the
 * payload's decision and not this reader's, because the rule is the same on every surface and
 * belongs where the rest of the visibility rules live.
 *
 * The contended range is not here: buffers are deliberately not part of what the customer
 * sees, and the payload has no bucket that would let them out.
 */
final readonly class BookingItemSnapshot
{
    public function __construct(
        public int $sort,
        public int $serviceId,
        public string $serviceName,
        public int $staffId,
        public string $staffName,
        public bool $staffIsImplicit,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public Money $price,
    ) {
        if ($sort < 0) {
            throw new InvalidArgumentException(sprintf('A sort is zero-based and never negative, not %d.', $sort));
        }

        if ($end <= $start) {
            throw new InvalidArgumentException('An item ends after it starts.');
        }
    }
}
