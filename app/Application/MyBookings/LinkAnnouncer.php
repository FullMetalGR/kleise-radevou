<?php

declare(strict_types=1);

namespace App\Application\MyBookings;

/**
 * The port a minted My bookings link is announced through, the link's counterpart of the
 * transition publisher: called after the row is written, never inside a transaction, and never
 * throwing, since the row is written and a mail that could not be queued is a fault to log, not
 * an answer the form changes for.
 */
interface LinkAnnouncer
{
    public function announce(BookingsLinkRequested $requested): void;
}
