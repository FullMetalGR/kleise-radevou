<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;

/**
 * Why and since when a tenant is suspended: the instant and the reason the console's suspend
 * wrote, the two set together and cleared together.
 */
final readonly class TenantSuspension
{
    public function __construct(
        public DateTimeImmutable $at,
        public string $reason,
    ) {}
}
