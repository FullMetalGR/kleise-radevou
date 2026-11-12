<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * The signal that rolls a write back when the staff-days it locked no longer answer for the items
 * the re-read under the lock found. Three writes throw it: a guarded transition (GuardedWrite)
 * and the pay hop's write (PostgresCheckoutRecords), which records a session and changes no
 * status, where the re-read's staff-days are not the set locked, the hop's also where the re-read
 * finds no items; and RescheduleBooking where the set locked does not cover them, since that set
 * is the union of two placements. It is thrown out of the transaction closure because that is
 * what a rollback is here: returning from the closure commits, and committing would leave the
 * revision bumped and the work undone.
 *
 * It reaches no caller. The three catch it and run again once: GuardedWrite and the hop's write
 * from the key-set read, RescheduleBooking from its read of the booking as sold, the key set
 * derived from it. GuardedWrite and RescheduleBooking answer the second as WriteContended, which
 * is the refusal with a meaning; PostgresCheckoutRecords answers the second as nothing written,
 * and the hop reads the row again.
 *
 * The error envelope answers it as busy all the same, beside WriteContended and a lock that was
 * not granted, because an exception that cannot escape today escapes the day somebody writes a
 * guard closure that raises one, and the difference between a 503 telling a customer to try
 * again and a 500 telling them nothing should not rest on that never happening.
 */
final class KeySetMoved extends RuntimeException
{
    public static function under(string $uuid): self
    {
        return new self(sprintf('The items of booking %s no longer touch the staff-days locked for it.', $uuid));
    }
}
