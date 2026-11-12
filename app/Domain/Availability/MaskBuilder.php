<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * The two masks for one staff-day, from rows already fetched and already decided. The open mask
 * is the member's rules expanded and every blackout that reaches them subtracted, tenant-wide
 * or their own, for this date, the whole day without a window and that window with one. The
 * not-booked mask starts all free and clears every block range it is handed, rounded outward to
 * the grid and clipped to the day.
 *
 * The two halves are built apart and joined by build, because they are read apart: the open
 * half comes from tables that carry no revision and is rebuilt on every read, while the
 * not-booked half is the one the cache keeps per staff-day revision.
 *
 * A blackout window covers every instant whose local reading falls inside it. On a plain day
 * that is one resolution. On a day the clocks change inside it the window is read under each
 * offset the day runs on and each reading is clipped to the stretch that offset holds, so both
 * passes of a repeated hour close and a reading the clocks skip closes nothing: an owner
 * closing their door is not served the parser's one guess, which errs toward more availability.
 * A rule window keeps resolving once, toward less.
 *
 * Which items block is not decided here. The blocking predicate has one implementation, the
 * query the repository runs before handing the ranges over, so the input on that side is a list
 * of ranges and nothing about the rows they came from.
 */
final readonly class MaskBuilder
{
    public function __construct(private RuleExpander $rules = new RuleExpander) {}

    /**
     * @param  iterable<AvailabilityRule>  $rules  this staff member's rules, any weekday, any validity
     * @param  iterable<Blackout>  $blackouts  the tenant's blackouts, any member, any date; those that do not reach this staff-day change nothing
     * @param  iterable<BlockRange>  $blocks  the block ranges of the items that block, whatever day they touch
     */
    public function build(StaffDayGeometry $geometry, int $staffId, iterable $rules, iterable $blackouts, iterable $blocks): StaffDayMasks
    {
        $hours = $this->hours($geometry, $rules);
        $unclosed = $this->unclosed($geometry, $staffId, $blackouts);

        return new StaffDayMasks($hours->and($unclosed), $this->notBooked($geometry, $blocks), $hours, $unclosed);
    }

    /**
     * The open half alone: the rules expanded, the blackouts that reach this staff-day
     * subtracted, which is the member's hours where no closure covers them.
     *
     * @param  iterable<AvailabilityRule>  $rules
     * @param  iterable<Blackout>  $blackouts
     */
    public function open(StaffDayGeometry $geometry, int $staffId, iterable $rules, iterable $blackouts): FreeBusyMask
    {
        return $this->hours($geometry, $rules)->and($this->unclosed($geometry, $staffId, $blackouts));
    }

    /**
     * The member's hours on this staff-day: their rules expanded, no blackout taken off.
     *
     * @param  iterable<AvailabilityRule>  $rules
     */
    public function hours(StaffDayGeometry $geometry, iterable $rules): FreeBusyMask
    {
        return $this->rules->expand($geometry, $rules);
    }

    /**
     * What no closure covers on this staff-day: every slot, less each blackout that reaches the
     * member, the whole day without a window and that window with one.
     *
     * @param  iterable<Blackout>  $blackouts
     */
    public function unclosed(StaffDayGeometry $geometry, int $staffId, iterable $blackouts): FreeBusyMask
    {
        if ($staffId < 1) {
            throw new InvalidArgumentException(sprintf('A staff id is a positive integer, not %d.', $staffId));
        }

        $unclosed = FreeBusyMask::allSet($geometry->width);

        foreach ($blackouts as $blackout) {
            if (! $blackout->appliesTo($staffId, $geometry->day)) {
                continue;
            }

            if ($blackout->start === null || $blackout->end === null) {
                $unclosed = FreeBusyMask::allClear($geometry->width);

                continue;
            }

            foreach ($geometry->offsetPeriods() as $period) {
                [$from, $to] = $period->clip(
                    $blackout->start->instantUnder($geometry, $period->offset),
                    $blackout->end->instantUnder($geometry, $period->offset),
                );

                $span = SlotSpan::outward($geometry, $from, $to);
                $unclosed = $unclosed->clear($span->from, $span->to);
            }
        }

        return $unclosed;
    }

    /**
     * The not-booked half alone: every slot free, then each block cleared.
     *
     * @param  iterable<BlockRange>  $blocks
     */
    public function notBooked(StaffDayGeometry $geometry, iterable $blocks): FreeBusyMask
    {
        $notBooked = FreeBusyMask::allSet($geometry->width);

        foreach ($blocks as $block) {
            $span = SlotSpan::outward($geometry, $block->start, $block->end);
            $notBooked = $notBooked->clear($span->from, $span->to);
        }

        return $notBooked;
    }
}
