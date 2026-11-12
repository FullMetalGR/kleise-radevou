<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * The two masks for one staff member over a window of consecutive days: each day's open mask
 * concatenated in the window's order, and the not-booked masks likewise, so a run that crosses
 * a midnight is found by the same shift-and as one inside a day. Both are the window's width,
 * and the window is kept beside them because it is what an index means.
 *
 * This is where the per-segment rule lives, because the two masks exist for it. A segment can
 * start at slot s when the not-booked mask holds a run of the buffer before, the duration and
 * the buffer after together, beginning at s minus the buffer before, and the open mask holds a
 * run of the duration alone beginning at s. Buffers occupy the staff member, so they contend
 * with bookings; they are not required to fall inside opening hours, so the last appointment
 * of the day is offered when its cleanup buffer runs past closing.
 *
 * The window's edges read as busy: a start whose setup buffer would begin before the first
 * slot, or whose cleanup buffer would end after the last, is not offered, and a caller that
 * cares widens the window. A processing gap plays no part here; it belongs to the chain.
 */
final readonly class WindowMasks
{
    public function __construct(
        public WindowGeometry $window,
        public FreeBusyMask $open,
        public FreeBusyMask $notBooked,
    ) {
        if ($open->width !== $window->width || $notBooked->width !== $window->width) {
            throw new InvalidArgumentException(sprintf('The masks of a window are its width, %d, not %d and %d.', $window->width, $open->width, $notBooked->width));
        }
    }

    /**
     * The masks of the window's days concatenated in its order, one pair per day and each pair
     * the width of its day.
     *
     * @param  iterable<StaffDayMasks>  $days
     */
    public static function of(WindowGeometry $window, iterable $days): self
    {
        $open = [];
        $notBooked = [];

        foreach ($days as $masks) {
            $day = $window->days[count($open)] ?? null;

            if ($day !== null && $masks->open->width !== $day->width) {
                throw new InvalidArgumentException(sprintf('The masks for %s are %d wide, not its %d.', $day->day, $masks->open->width, $day->width));
            }

            $open[] = $masks->open;
            $notBooked[] = $masks->notBooked;
        }

        if (count($open) !== count($window->days)) {
            throw new InvalidArgumentException(sprintf('A window of %d days takes %d staff-day masks, not %d.', count($window->days), count($window->days), count($open)));
        }

        return new self($window, self::concatenated($open), self::concatenated($notBooked));
    }

    /**
     * The mask whose bit s is set when the segment can start at slot s. The minutes round up
     * to slots first, each on its own, so that a start is never offered whose work does not
     * fit. The not-booked runs are shifted toward higher indices by the buffer before, so that
     * bit s of the shifted mask reads what began at s minus the buffer.
     */
    public function segmentStarts(SegmentShape $segment): FreeBusyMask
    {
        $granularity = $this->window->granularity;
        $before = $granularity->slotsFor($segment->bufferBeforeMin);
        $duration = $granularity->slotsFor($segment->durationMin);
        $after = $granularity->slotsFor($segment->bufferAfterMin);

        return $this->notBooked->runs($before + $duration + $after)
            ->shiftToHigher($before)
            ->and($this->open->runs($duration));
    }

    /**
     * One or more masks concatenated in order.
     *
     * @param  list<FreeBusyMask>  $masks
     */
    private static function concatenated(array $masks): FreeBusyMask
    {
        $whole = $masks[0];

        foreach (array_slice($masks, 1) as $next) {
            $whole = $whole->concat($next);
        }

        return $whole;
    }
}
