<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * One staff-day: the (staff_id, day) pair that is the unit of contention and the primary key of
 * the mutex table. The day is a calendar date in the tenant's zone, spelled the way the date
 * column takes it, so it binds without conversion and orders as text the way it orders as a date.
 */
final readonly class LockKey
{
    private const string DAY = '/^(\d{4})-(\d{2})-(\d{2})$/';

    public function __construct(public int $staffId, public string $day)
    {
        if ($staffId < 1) {
            throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
        }

        if (preg_match(self::DAY, $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException(sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
        }
    }

    /**
     * Ascending by staff member, then by day: the lock order.
     */
    public function compare(self $other): int
    {
        $byStaff = $this->staffId <=> $other->staffId;

        return $byStaff !== 0 ? $byStaff : strcmp($this->day, $other->day);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    /**
     * The pair as one string, "staff:day", for keying a map by staff-day.
     */
    public function toString(): string
    {
        return $this->staffId.':'.$this->day;
    }
}
