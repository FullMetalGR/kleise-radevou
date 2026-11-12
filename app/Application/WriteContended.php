<?php

declare(strict_types=1);

namespace App\Application;

use RuntimeException;

/**
 * The booking's items moved out from under the lock twice running, so the staff-days held were
 * twice not the staff-days that needed holding.
 *
 * Only a concurrent reschedule moves them, and the protocol answers one by rolling back and
 * running again from step 1. A second one in a row is contention rather than a fault: nothing
 * is wrong with the booking, somebody else is simply moving it while this caller tries to
 * settle it. It is answered as busy, which is what the API says when a write could not get a
 * quiet moment, and the caller offers the action again.
 */
final class WriteContended extends RuntimeException
{
    public static function keptMoving(string $uuid, int $attempts): self
    {
        return new self(sprintf(
            'The items of booking %s moved under the lock on each of %d attempts; the write is contended.',
            $uuid,
            $attempts,
        ));
    }
}
