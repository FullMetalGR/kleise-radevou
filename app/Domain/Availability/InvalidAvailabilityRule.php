<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * An availability rule that cannot describe a window: one that closes before or as it opens,
 * a weekday outside ISO 1 to 7, or a validity range that ends before it starts. Refused at
 * construction rather than expanded to nothing, because a rule that can never apply is a bug
 * in whoever wrote the row and not a day off.
 */
final class InvalidAvailabilityRule extends InvalidArgumentException
{
    public static function window(WallClockTime $start, WallClockTime $end): self
    {
        return new self(sprintf('An availability rule opens before it closes, not from %s to %s.', $start->format(), $end->format()));
    }

    public static function weekday(int $weekday): self
    {
        return new self(sprintf('A weekday is ISO 1 to 7, not %d.', $weekday));
    }

    public static function validity(string $from, string $to): self
    {
        return new self(sprintf('A validity range starts no later than it ends, not from %s to %s.', $from, $to));
    }
}
