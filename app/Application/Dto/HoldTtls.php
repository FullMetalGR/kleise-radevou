<?php

declare(strict_types=1);

namespace App\Application\Dto;

use InvalidArgumentException;

/**
 * The two hold lifetimes a tenant's settings row names: how long a checkout hold lives, in
 * minutes, and how long an approval request waits, in hours, for a service that names no window
 * of its own. The payment window is not here.
 */
final readonly class HoldTtls
{
    private const int MINUTES_PER_HOUR = 60;

    public function __construct(
        public int $checkoutMinutes,
        public int $approvalHours,
    ) {
        if ($checkoutMinutes < 1) {
            throw new InvalidArgumentException(sprintf('A checkout TTL is at least one minute, not %d.', $checkoutMinutes));
        }

        if ($approvalHours < 1) {
            throw new InvalidArgumentException(sprintf('An approval TTL is at least one hour, not %d.', $approvalHours));
        }
    }

    /**
     * The approval window in minutes: the service's own hours when it names some, else the
     * tenant's default.
     */
    public function approvalMinutes(?int $serviceHours): int
    {
        if ($serviceHours !== null && $serviceHours < 1) {
            throw new InvalidArgumentException(sprintf('A service approval window is null or at least one hour, not %d.', $serviceHours));
        }

        return ($serviceHours ?? $this->approvalHours) * self::MINUTES_PER_HOUR;
    }
}
