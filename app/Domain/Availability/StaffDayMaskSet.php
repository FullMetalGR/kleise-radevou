<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * The two masks of every staff-day of one availability read: a window of consecutive days and,
 * for each staff member asked for, one StaffDayMasks per day of the window in the window's
 * order. The window is kept beside the masks because it is what a day's position means, and a
 * member's days concatenate into the WindowMasks the chain resolver takes.
 *
 * Every member asked for is present, a member with no rows reading closed all day and unbooked,
 * so a caller never has to guess what an absent key means.
 */
final readonly class StaffDayMaskSet
{
    /**
     * @param  array<int, list<StaffDayMasks>>  $byStaff  keyed by staff id, one entry per day of the window in its order
     */
    public function __construct(
        public WindowGeometry $window,
        private array $byStaff,
    ) {
        if ($byStaff === []) {
            throw new InvalidArgumentException('A mask set covers at least one staff member.');
        }

        foreach ($byStaff as $staffId => $days) {
            if ($staffId < 1) {
                throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
            }

            if (count($days) !== count($window->days)) {
                throw new InvalidArgumentException(sprintf('Staff member %d has masks for %d days of a window of %d.', $staffId, count($days), count($window->days)));
            }

            foreach ($days as $position => $masks) {
                $day = $window->days[$position];

                if ($masks->open->width !== $day->width) {
                    throw new InvalidArgumentException(sprintf('The masks of staff member %d for %s are %d wide, not its %d.', $staffId, $day->day, $masks->open->width, $day->width));
                }
            }
        }
    }

    /**
     * The staff members the set was built for, in the order asked.
     *
     * @return list<int>
     */
    public function staffIds(): array
    {
        return array_keys($this->byStaff);
    }

    /**
     * A member's masks, one per day of the window in its order.
     *
     * @return list<StaffDayMasks>
     */
    public function daysOf(int $staffId): array
    {
        return $this->byStaff[$staffId] ?? throw new InvalidArgumentException(sprintf('The mask set holds no staff member %d.', $staffId));
    }

    /**
     * A member's masks on one day of the window, by its spelling.
     */
    public function on(int $staffId, string $day): StaffDayMasks
    {
        $days = $this->daysOf($staffId);

        foreach ($this->window->days as $position => $geometry) {
            if ($geometry->day === $day) {
                return $days[$position];
            }
        }

        throw new InvalidArgumentException(sprintf('The window from %s to %s has no day %s.', $this->window->days[0]->day, $this->window->days[count($this->window->days) - 1]->day, $day));
    }

    /**
     * A member's days concatenated into the pair of window masks.
     */
    public function windowOf(int $staffId): WindowMasks
    {
        return WindowMasks::of($this->window, $this->daysOf($staffId));
    }
}
