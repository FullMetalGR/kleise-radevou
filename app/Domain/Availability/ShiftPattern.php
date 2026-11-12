<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * One member's repeating week, a shift_patterns row as a value: the first date it holds, how
 * many weeks it repeats over, the last date it holds or none, and its windows in the weekday
 * rules' shape, each an AvailabilityRule with no validity dates of its own, since the pattern's
 * dates bound them all. Whose pattern it is is not on it, for the reason the rule gives: the
 * resolver is handed one member's rows and must not know whose they are.
 *
 * A cadence above one counts weeks from the week holding starts_on, on the tenant's week start,
 * that week being week one; ShiftResolver applies it, since the week start is the tenant's and
 * not the pattern's.
 */
final readonly class ShiftPattern
{
    public const int MAX_CADENCE = 4;

    /**
     * @param  string  $startsOn  YYYY-MM-DD
     * @param  string|null  $endsOn  YYYY-MM-DD, inclusive, or null for never
     * @param  list<AvailabilityRule>  $windows
     */
    public function __construct(
        public string $startsOn,
        public int $cadenceWeeks,
        public ?string $endsOn,
        public array $windows,
    ) {
        CalendarDay::spelled($startsOn);

        if ($cadenceWeeks < 1 || $cadenceWeeks > self::MAX_CADENCE) {
            throw InvalidShiftPattern::cadence($cadenceWeeks);
        }

        if ($endsOn !== null && CalendarDay::compare($endsOn, $startsOn) < 0) {
            throw InvalidShiftPattern::ends($startsOn, $endsOn);
        }

        foreach ($windows as $window) {
            if ($window->validFrom !== null || $window->validTo !== null) {
                throw InvalidShiftPattern::validity();
            }
        }
    }

    /**
     * Whether the date falls inside the pattern's dates, both ends inclusive.
     */
    public function holds(string $day): bool
    {
        return CalendarDay::compare($day, $this->startsOn) >= 0
            && ($this->endsOn === null || CalendarDay::compare($day, $this->endsOn) <= 0);
    }
}
