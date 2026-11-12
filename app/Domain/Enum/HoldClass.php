<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Which kind of hold a booking is on: stored on the row because the TTL cannot be read backwards
 * into the reason, and the reason decides which acknowledgement the customer gets. The
 * bookings.hold_class CHECK constraint lists the same three, and a test holds the two lists to
 * each other.
 */
enum HoldClass: string
{
    case Checkout = 'checkout';
    case Approval = 'approval';
    case Payment = 'payment';

    /**
     * The default TTL of the class, in minutes. The tenant's settings row overrides these
     * once the settings are read.
     */
    public function defaultTtlMinutes(): int
    {
        return match ($this) {
            self::Checkout => 15,
            self::Approval => 48 * 60,
            self::Payment => 24 * 60,
        };
    }
}
