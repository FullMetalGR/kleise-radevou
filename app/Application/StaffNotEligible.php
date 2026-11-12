<?php

declare(strict_types=1);

namespace App\Application;

use InvalidArgumentException;

/**
 * A hold named a staff member for an item they do not perform: the pick is not among the
 * service's active assigned members, so the chain cannot be built from it. Named by the item
 * that named it and by the member it named.
 *
 * An invalid argument, since the segment would refuse the same pick as a bug and this is what
 * keeps it from becoming one. Typed so that the HTTP boundary can answer it with a sentence
 * without reading the catalog a second time to find out what it already knows.
 */
final class StaffNotEligible extends InvalidArgumentException
{
    private function __construct(
        public readonly int $segment,
        public readonly int $staffId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function at(int $segment, int $staffId): self
    {
        return new self($segment, $staffId, sprintf('Item %d names staff member %d, who does not perform its service.', $segment, $staffId));
    }
}
