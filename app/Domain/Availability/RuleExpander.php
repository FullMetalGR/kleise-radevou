<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * One staff member's availability rules on one day, as the availability half of the open
 * mask: the rules in force that day, each window resolved to instants in the tenant's zone
 * and cut to slots through the geometry, unioned into an otherwise empty mask. Several
 * windows on one day are normal, a split day around lunch being two, and windows that overlap
 * simply union.
 *
 * Whose rules they are is not asked. The tenant's opening hours are the implicit member's
 * rules under another name, and nothing here can or may tell them apart.
 */
final readonly class RuleExpander
{
    /**
     * The rules in force on a day, in the order given: those whose weekday matches and whose
     * validity range contains the date.
     *
     * @param  iterable<AvailabilityRule>  $rules
     * @return list<AvailabilityRule>
     */
    public function inForce(string $day, iterable $rules): array
    {
        $inForce = [];

        foreach ($rules as $rule) {
            if ($rule->appliesOn($day)) {
                $inForce[] = $rule;
            }
        }

        return $inForce;
    }

    /**
     * The mask with the slots of every window in force set and nothing else. A window rounds
     * inward to the grid, since a member on duty from 09:03 cannot take a slot starting 09:00.
     *
     * @param  iterable<AvailabilityRule>  $rules
     */
    public function expand(StaffDayGeometry $geometry, iterable $rules): FreeBusyMask
    {
        $mask = FreeBusyMask::allClear($geometry->width);

        foreach ($this->inForce($geometry->day, $rules) as $rule) {
            $span = SlotSpan::inward($geometry, $rule->start->instantOn($geometry), $rule->end->instantOn($geometry));
            $mask = $mask->set($span->from, $span->to);
        }

        return $mask;
    }
}
