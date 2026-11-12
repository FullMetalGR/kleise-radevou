<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Application\Dto\SoldItem;

/**
 * The read a move takes before it opens anything, bound to one tenant by whoever built it: what
 * a booking was sold as, in chain order.
 *
 * It is the reschedule's own step 1 and it answers two questions with one statement, because
 * they are two readings of the same rows: which staff-days the booking is leaving, from the
 * contended ranges, and what the new chain is planned from, the services, the members and the
 * prices. A move that read them separately would issue two statements a moment apart and could
 * plan from one version of the booking while locking for another.
 *
 * It carries no status and no customer, for the reason BookingLookup carries neither: the
 * booking a move writes is the one its own lock re-reads, and a pre-read whose shape cannot
 * hold a status cannot have its status mistaken for evidence.
 *
 * This is not BookingLookup with more on it. That port exists to name staff-days for the
 * guarded transitions, which never plan anything and must be able to say so; a move is the one
 * write outside that sequence, and giving that port a method only the move calls would put the
 * planning read in front of seven transitions that must not take it.
 */
interface SoldChain
{
    /**
     * @return non-empty-list<SoldItem>|null the items in chain order, or null where no booking of this tenant carries the identifier
     */
    public function of(string $uuid): ?array;
}
