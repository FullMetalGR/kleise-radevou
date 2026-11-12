<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;

/**
 * What a tenant's own schema holds, as the console's detail counts it: the active services, the
 * active staff members with the implicit one counted, the bookings per status, every status of
 * the machine named and nought where none stands in it, in the machine's order, and the latest
 * booking's creation instant, null where none was ever made.
 */
final readonly class TenantActivity
{
    /**
     * @param  array<string, int>  $bookings  by status value, every BookingStatus case in its order
     */
    public function __construct(
        public int $services,
        public int $activeStaff,
        public array $bookings,
        public ?DateTimeImmutable $lastActivityAt,
    ) {}
}
