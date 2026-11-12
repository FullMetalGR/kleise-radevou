<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * A shift pattern that cannot describe a repeating week: a cadence outside one to four weeks, an
 * end before its start, or a window carrying validity dates of its own, which the pattern's
 * dates replace. Refused at construction, as an availability rule is, because a row the CHECKs
 * should have kept out is a bug in whoever wrote it.
 */
final class InvalidShiftPattern extends InvalidArgumentException
{
    public static function cadence(int $weeks): self
    {
        return new self(sprintf('A shift pattern repeats every 1 to 4 weeks, not every %d.', $weeks));
    }

    public static function ends(string $startsOn, string $endsOn): self
    {
        return new self(sprintf('A shift pattern cannot end on %s, before it starts on %s.', $endsOn, $startsOn));
    }

    public static function validity(): self
    {
        return new self('A shift pattern\'s window carries no validity dates; the pattern\'s own dates bound it.');
    }
}
