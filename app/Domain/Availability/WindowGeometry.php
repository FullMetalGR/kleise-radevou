<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A window of consecutive staff-days in the tenant's zone as one strip of slots: the days in
 * date order, each with its own width, the sum of those widths, and the walk from a window
 * index back to the day it falls in. Day two's slot k sits at the width of day one plus k,
 * exactly where the mask concatenation puts it, so a window mask and this geometry agree on
 * what every index means and a segment whose buffer crosses a midnight is found by one shift
 * rather than by stitching days together.
 *
 * The walk is built once here, and every conversion between a window index and an instant or
 * a day goes through it and then through the day's own geometry. Nothing else counts widths.
 * The days must be consecutive, in one zone, at one granularity: a gap or a repeat would leave
 * an index meaning nothing or two things.
 */
final readonly class WindowGeometry
{
    /**
     * @var list<StaffDayGeometry>
     */
    public array $days;

    public int $width;

    public DateTimeZone $zone;

    public Granularity $granularity;

    /**
     * @var array<string, int> the position in days of each day, by its spelling
     */
    private array $positions;

    /**
     * @var list<int> the window index of each day's first slot
     */
    private array $offsets;

    /**
     * @param  list<StaffDayGeometry>  $days
     */
    private function __construct(array $days)
    {
        if ($days === []) {
            throw new InvalidArgumentException('A window is at least one day long.');
        }

        $first = $days[0];
        $previous = null;
        $positions = [];
        $offsets = [];
        $width = 0;

        foreach ($days as $position => $day) {
            if ($day->zone->getName() !== $first->zone->getName()) {
                throw new InvalidArgumentException(sprintf('A window lies in one zone, not %s and %s.', $first->zone->getName(), $day->zone->getName()));
            }

            if (! $day->granularity->equals($first->granularity)) {
                throw new InvalidArgumentException(sprintf('A window has one granularity, not %d and %d minutes.', $first->granularity->minutes, $day->granularity->minutes));
            }

            if ($previous !== null && $previous->nextMidnight->getTimestamp() !== $day->midnight->getTimestamp()) {
                throw new InvalidArgumentException(sprintf('A window is consecutive days in date order, not %s followed by %s.', $previous->day, $day->day));
            }

            $positions[$day->day] = $position;
            $offsets[] = $width;
            $width += $day->width;
            $previous = $day;
        }

        $this->days = $days;
        $this->width = $width;
        $this->zone = $first->zone;
        $this->granularity = $first->granularity;
        $this->positions = $positions;
        $this->offsets = $offsets;
    }

    /**
     * @param  iterable<StaffDayGeometry>  $days  consecutive, in date order
     */
    public static function of(iterable $days): self
    {
        $list = [];

        foreach ($days as $day) {
            $list[] = $day;
        }

        return new self($list);
    }

    /**
     * The window of a number of days starting on a day, each built in the zone at the
     * granularity.
     */
    public static function spanning(string $firstDay, int $dayCount, DateTimeZone $zone, Granularity $granularity): self
    {
        $days = [];

        for ($later = 0; $later < $dayCount; $later++) {
            $days[] = StaffDayGeometry::of(CalendarDay::plusDays($firstDay, $later), $zone, $granularity);
        }

        return new self($days);
    }

    public function contains(string $day): bool
    {
        return isset($this->positions[$day]);
    }

    /**
     * The window index of the day's first slot.
     */
    public function offsetOf(string $day): int
    {
        return $this->offsets[$this->positionOfDay($day)];
    }

    /**
     * The geometry of a day of the window, by its spelling, without walking an index back.
     */
    public function dayFor(string $day): StaffDayGeometry
    {
        return $this->days[$this->positionOfDay($day)];
    }

    /**
     * The day a slot index falls in.
     */
    public function dayAt(int $index): StaffDayGeometry
    {
        return $this->days[$this->positionOf($index)];
    }

    /**
     * The instant a position falls on, in the tenant's zone, through the day it falls in; the
     * width is the next midnight after the last day.
     */
    public function instantAt(int $index): DateTimeImmutable
    {
        if ($index < 0 || $index > $this->width) {
            throw new InvalidArgumentException(sprintf('An index on the window from %s to %s lies between 0 and %d, not %d.', $this->firstDay(), $this->lastDay(), $this->width, $index));
        }

        $position = $index === $this->width ? count($this->days) - 1 : $this->positionOf($index);

        return $this->days[$position]->instantAt($index - $this->offsets[$position]);
    }

    /**
     * The position in days of a day of the window, by its spelling.
     */
    private function positionOfDay(string $day): int
    {
        if (! isset($this->positions[$day])) {
            throw new InvalidArgumentException(sprintf('The window from %s to %s has no day %s.', $this->firstDay(), $this->lastDay(), $day));
        }

        return $this->positions[$day];
    }

    /**
     * The position in days of the day holding a slot index: the last day whose offset is at
     * or below it, found by bisection so that a window of sixty days is walked in six steps.
     */
    private function positionOf(int $index): int
    {
        if ($index < 0 || $index >= $this->width) {
            throw new InvalidArgumentException(sprintf('A slot index on a window of width %d lies between 0 and %d, not %d.', $this->width, $this->width - 1, $index));
        }

        $low = 0;
        $high = count($this->offsets) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->offsets[$middle] <= $index) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low;
    }

    private function firstDay(): string
    {
        return $this->days[0]->day;
    }

    private function lastDay(): string
    {
        return $this->days[count($this->days) - 1]->day;
    }
}
