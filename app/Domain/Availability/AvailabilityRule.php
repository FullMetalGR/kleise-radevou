<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * One window of a member's weekly pattern as a value (shift_patterns' windows): a recurring
 * weekly window for one staff member. The staff member is not on it, because the expander is
 * handed one member's windows and must not know whose they are: the tenant's opening hours are
 * the implicit member's windows under another name, and nothing here can tell the difference.
 *
 * The window is two wall-clock readings in the tenant's zone, no instant and no conversion. The
 * two optional calendar days, both ends inclusive and either end open, bound the dates the
 * window holds on, as the rule expander reads them; a pattern refuses a window carrying either
 * (ShiftPattern), its dates being the pattern's own.
 */
final readonly class AvailabilityRule
{
    /**
     * @param  int  $weekday  ISO 1 to 7
     * @param  string|null  $validFrom  YYYY-MM-DD, or null for no lower bound
     * @param  string|null  $validTo  YYYY-MM-DD, or null for no upper bound
     */
    public function __construct(
        public int $weekday,
        public WallClockTime $start,
        public WallClockTime $end,
        public ?string $validFrom = null,
        public ?string $validTo = null,
    ) {
        if ($weekday < 1 || $weekday > 7) {
            throw InvalidAvailabilityRule::weekday($weekday);
        }

        if (! $start->isBefore($end)) {
            throw InvalidAvailabilityRule::window($start, $end);
        }

        if ($validFrom !== null) {
            CalendarDay::spelled($validFrom);
        }

        if ($validTo !== null) {
            CalendarDay::spelled($validTo);
        }

        if ($validFrom !== null && $validTo !== null && CalendarDay::compare($validFrom, $validTo) > 0) {
            throw InvalidAvailabilityRule::validity($validFrom, $validTo);
        }
    }

    /**
     * Whether the rule is in force on a day: its weekday, inside its validity range.
     */
    public function appliesOn(string $day): bool
    {
        if (CalendarDay::weekday($day) !== $this->weekday) {
            return false;
        }

        if ($this->validFrom !== null && CalendarDay::compare($day, $this->validFrom) < 0) {
            return false;
        }

        return $this->validTo === null || CalendarDay::compare($day, $this->validTo) <= 0;
    }
}
