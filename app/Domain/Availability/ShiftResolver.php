<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * A member's windows in force on a date, in one order: the date's override where one exists, an
 * empty one meaning not working; else the pattern's windows for the date's weekday when the date
 * is inside the pattern's dates and the weeks from the week holding starts_on to the week holding
 * the date, on the tenant's week start, are a multiple of the cadence; else none.
 *
 * The answer is a list of AvailabilityRule on the date's weekday, what RuleExpander::expand takes,
 * so the open mask's inward rounding, its clock-change days and the closures MaskBuilder::open
 * subtracts after it are what they were under the weekday rules. This is the one spelling of the
 * cadence; every per-date read of a member's hours goes through it.
 *
 * Beside it, what the pattern says of a date's weekday with the cadence left aside (usualOn): the
 * week the member usually works, for telling a weekday they never work from a date their pattern
 * skips or does not reach. It offers nothing; the hours of a date are windowsOn's alone.
 */
final readonly class ShiftResolver
{
    /**
     * @param  array<string, ShiftOverride>  $overrides  the member's overrides keyed by date; those of other dates change nothing
     * @param  int  $startOfWeek  the tenant's week start, ISO numbered, 1 for Monday
     * @return list<AvailabilityRule>
     */
    public function windowsOn(?ShiftPattern $pattern, array $overrides, int $startOfWeek, string $day): array
    {
        $override = $overrides[CalendarDay::spelled($day)] ?? null;

        if ($override !== null) {
            return $override->windows;
        }

        if ($pattern === null || ! $pattern->holds($day)) {
            return [];
        }

        if (CalendarDay::weeksBetween($pattern->startsOn, $day, $startOfWeek) % $pattern->cadenceWeeks !== 0) {
            return [];
        }

        return self::onWeekday($pattern, $day);
    }

    /**
     * The pattern's windows on the date's weekday when the date is inside the pattern's dates,
     * whether or not the cadence gives that week: an empty list is a weekday the pattern never
     * works. Null where there is no pattern or the date is outside its dates, since nothing
     * then says what the weekday usually holds.
     *
     * @return list<AvailabilityRule>|null
     */
    public function usualOn(?ShiftPattern $pattern, string $day): ?array
    {
        if ($pattern === null || ! $pattern->holds(CalendarDay::spelled($day))) {
            return null;
        }

        return self::onWeekday($pattern, $day);
    }

    /**
     * @return list<AvailabilityRule>
     */
    private static function onWeekday(ShiftPattern $pattern, string $day): array
    {
        $weekday = CalendarDay::weekday($day);

        return array_values(array_filter($pattern->windows, fn (AvailabilityRule $window): bool => $window->weekday === $weekday));
    }
}
