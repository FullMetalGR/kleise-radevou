<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Booking\LockKeySet;

/**
 * The masks a per-booking availability read goes through, bound to one tenant by whoever built
 * it: the masks of AvailabilityRepository with one booking's items left out of what counts as
 * busy.
 *
 * Left out by rebuilding, never by subtracting. The staff-days the booking stands on are built
 * from the rows with its items excluded, because a blackout or a buffer added since the sale can
 * overlap the booking's ranges, and taking those ranges off a cached mask would offer that
 * closed time. Those masks are true for this booking's read alone, so they are read past the
 * shared cache and stored nowhere. Every other staff-day is read as AvailabilityRepository
 * reads it, through the cache.
 *
 * The staff-days are the ones the booking's contended ranges touch in the tenant's zone, the set
 * LockKeySet::forBlocks names, a range crossing local midnight touching two. Naming a staff-day
 * the booking does not stand on costs a rebuild and changes nothing; naming too few would leave
 * the booking busy where it stands.
 */
interface AvailabilityLeavingOut
{
    /**
     * @param  string  $uuid  the booking whose items count as free
     * @param  LockKeySet  $standsOn  the staff-days its items stand on
     * @param  non-empty-list<int>  $staffIds
     * @param  string  $firstDay  a tenant-local calendar day, YYYY-MM-DD
     * @param  int  $dayCount  at least one
     */
    public function masksLeavingOut(string $uuid, LockKeySet $standsOn, array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet;
}
