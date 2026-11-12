<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Tenancy\Tenant;

/**
 * A booking's customer: the bookings and their items are kept 24 months after the last segment
 * ends, the accounting record, and then lose the person, the customer fields nulled and the
 * rows kept. The last segment end is the booking's latest item's end, as the Customers list
 * reads a visit by its first item's start, and the line reads it whatever the booking's
 * status: a booking cancelled, rejected or expired is a record of somebody as much as one that
 * happened.
 *
 * The statement is erasure's primitive over those bookings, so retention and the admin's
 * erasure lose a person the same way: the three customer columns, the intake rows and both
 * token columns, in one statement. A booking already erased is not chosen again.
 */
final readonly class BookingCustomersLine implements TenantLine
{
    /** How long a booking keeps its customer after its last segment ends. */
    public const string KEPT = 'P24M';

    public function __construct(private Erasure $erasure, private Clock $clock) {}

    public function table(): string
    {
        return 'bookings';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->erasure->endedBefore($tenant, Cutoff::before($this->clock, self::KEPT));
    }
}
