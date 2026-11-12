<?php

declare(strict_types=1);

namespace App\Application\Dto;

use InvalidArgumentException;

/**
 * One item of the chain a customer is asking about: the service, and the staff member
 * they picked for it or null for no preference. The null is explicit on the wire and
 * explicit here, so an absent pick and no pick are one thing.
 */
final readonly class SegmentChoice
{
    public function __construct(
        public int $serviceId,
        public ?int $staffId,
    ) {
        if ($serviceId < 1) {
            throw new InvalidArgumentException(sprintf('A service id is a positive integer, not %d.', $serviceId));
        }

        if ($staffId !== null && $staffId < 1) {
            throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
        }
    }
}
