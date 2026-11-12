<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Domain\Booking\StaffBlock;

/**
 * The read a guarded transition takes before it opens anything, bound to one tenant by whoever
 * built it: step 1 of the guarded write, and nothing else.
 *
 * It answers the contended ranges of a booking's items and not the booking. That is the whole
 * of what step 1 is for - naming the staff-days to lock - and the pre-read is not authoritative
 * about anything else: the status it would have carried is exactly what a guard must never
 * judge, and a shape that cannot carry it cannot leak it. What the transition then decides on
 * is the re-read under the lock.
 *
 * The ranges are the ones the mutex rows stand for, buffers included. Reading the customer's
 * range instead would name fewer staff-days than the booking occupies whenever a buffer crosses
 * local midnight, and step 5, which recomputes the set from the ranges it locked against, would
 * then find a difference on every such booking and answer busy for a race that never happened.
 */
interface BookingLookup
{
    /**
     * @return non-empty-list<StaffBlock>|null the item ranges in chain order, or null where no booking has that identifier
     */
    public function blocksOf(string $uuid): ?array;
}
