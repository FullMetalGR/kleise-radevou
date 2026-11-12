<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * Minutes per slot: a divisor of sixty, five by default. Dividing sixty is what makes the hour
 * a clock change adds or removes a whole number of slots, so a staff-day's width stays an
 * integer on the days the width is not 1440 minutes over the step. Durations, buffers and
 * processing times round up to whole slots, never down: a start rounded down would offer a slot
 * the work does not fit in.
 */
final readonly class Granularity
{
    private const int DEFAULT_MINUTES = 5;

    private function __construct(public int $minutes) {}

    /**
     * @throws InvalidGranularity
     */
    public static function minutes(int $minutes): self
    {
        if ($minutes < 1 || 60 % $minutes !== 0) {
            throw InvalidGranularity::minutes($minutes);
        }

        return new self($minutes);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_MINUTES);
    }

    /**
     * A duration in minutes rounded up to whole slots: 50 minutes at fifteen is four, and zero
     * is zero. A negative duration is nobody's, and is refused rather than rounded to nothing.
     */
    public function slotsFor(int $minutes): int
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException(sprintf('A duration is a number of minutes at or above zero, not %d.', $minutes));
        }

        return intdiv($minutes + $this->minutes - 1, $this->minutes);
    }

    public function equals(self $other): bool
    {
        return $this->minutes === $other->minutes;
    }
}
