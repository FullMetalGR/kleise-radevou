<?php

declare(strict_types=1);

namespace App\Application\Repository;

use Closure;

/**
 * The port a booking use case writes through, bound to one tenant by whoever built it. Steps 2
 * and 7 of the write protocol are here, around the work: the tenant transaction is opened with
 * its settings and its assertions, the work runs with a writer bound to that transaction, and
 * the commit follows the work's return. A throw from the work rolls the transaction back and
 * comes out untouched, which is how a domain conflict leaves nothing behind.
 */
interface BookingRepository
{
    /**
     * @template T
     *
     * @param  Closure(BookingWriter): T  $work
     * @return T
     */
    public function transaction(Closure $work): mixed;
}
