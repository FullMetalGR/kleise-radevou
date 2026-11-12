<?php

declare(strict_types=1);

namespace App\Application\Repository;

use Closure;

/**
 * The port the sweeper writes through, bound to one tenant by whoever built it. One call is
 * one batch in one transaction of its own, with the tenant's settings and the protocol's
 * assertions around it, exactly as a booking transaction has them.
 *
 * It is not BookingRepository and the thing it hands the work is not a BookingWriter. 4.8 is a
 * separate job with different rules, because it is periodic and idempotent where the booking
 * path is neither: it takes an advisory lock the booking path may never take, it reads past
 * rows the booking path must wait for, and it locks no staff-day at all. A port that carried
 * both vocabularies would let either half reach for the other's statements, and the one thing
 * SKIP LOCKED must never come near is the booking transaction.
 */
interface HoldSweep
{
    /**
     * @template T
     *
     * @param  Closure(SweepBatch): T  $work
     * @return T
     */
    public function transaction(Closure $work): mixed;
}
