<?php

declare(strict_types=1);

namespace App\Application;

use InvalidArgumentException;

/**
 * A hold named a service the catalog does not offer: unknown, or inactive, which are alike
 * absent from the read side and alike a 404 on the wire. Named by the item that named it. An
 * invalid argument, since the HTTP boundary has already validated the item and a refusal here
 * is a bug or a catalog that changed between the two reads; typed so that the boundary can
 * answer it without a second read.
 */
final class ServiceNotBookable extends InvalidArgumentException
{
    private function __construct(
        public readonly int $segment,
        public readonly int $serviceId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function at(int $segment, int $serviceId): self
    {
        return new self($segment, $serviceId, sprintf('Item %d names service %d, which is not available.', $segment, $serviceId));
    }
}
