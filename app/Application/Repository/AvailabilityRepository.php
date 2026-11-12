<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Domain\Availability\StaffDayMaskSet;

/**
 * The port an availability read goes through, bound to one tenant by whoever built it: the two
 * masks for every staff-day of a set of staff members over a run of consecutive tenant-local
 * days, and the loads the staff assignment weighs. The rows behind them, and the one blocking
 * predicate that decides which items count, live on the other side of this port; the use case
 * sees masks and counts and nothing else.
 *
 * The window is exactly the days named. Widening it for a buffer or a chain that crosses the
 * window's edge is the caller's job.
 */
interface AvailabilityRepository
{
    /**
     * @param  non-empty-list<int>  $staffIds
     * @param  string  $firstDay  a tenant-local calendar day, YYYY-MM-DD
     * @param  int  $dayCount  at least one
     */
    public function masks(array $staffIds, string $firstDay, int $dayCount): StaffDayMaskSet;

    /**
     * The load of each staff member on one tenant-local day: how many of their blocking items
     * overlap that day, by the same predicate the masks read. A member with none is absent,
     * and a caller reads absence as zero.
     *
     * @param  non-empty-list<int>  $staffIds
     * @param  string  $day  a tenant-local calendar day, YYYY-MM-DD
     * @return array<int, int> by staff id
     */
    public function loads(array $staffIds, string $day): array;
}
