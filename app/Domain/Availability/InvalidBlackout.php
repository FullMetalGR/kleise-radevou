<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * A blackout that cannot describe a closure: a window with one end, a window that closes
 * before or as it opens, or a staff id no row can have. Refused at construction, for the same
 * reason an availability rule is: a row that means nothing is a bug in whoever wrote it.
 */
final class InvalidBlackout extends InvalidArgumentException
{
    public static function startAlone(WallClockTime $start): self
    {
        return new self(sprintf('A blackout window has both ends or neither, not a start of %s alone.', $start->format()));
    }

    public static function endAlone(WallClockTime $end): self
    {
        return new self(sprintf('A blackout window has both ends or neither, not an end of %s alone.', $end->format()));
    }

    public static function window(WallClockTime $start, WallClockTime $end): self
    {
        return new self(sprintf('A blackout window opens before it closes, not from %s to %s.', $start->format(), $end->format()));
    }

    public static function staffId(int $staffId): self
    {
        return new self(sprintf('A staff id is a positive integer, not %d.', $staffId));
    }
}
