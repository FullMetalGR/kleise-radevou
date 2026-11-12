<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The block range of one booking item that blocks: its block start and block end as instants,
 * and nothing else. There is no status and no expiry here because the blocking predicate has
 * exactly one implementation, the query that decides which items block before their ranges
 * are handed over, and a second reading of it in the builder would be a second
 * implementation. Whatever zone the two instants are spelled in, only their positions on the
 * timeline matter.
 */
final readonly class BlockRange
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($end < $start) {
            throw new InvalidArgumentException(sprintf(
                'A block range ends at or after it starts, not from %s to %s.',
                $start->format(DateTimeImmutable::RFC3339),
                $end->format(DateTimeImmutable::RFC3339),
            ));
        }
    }
}
