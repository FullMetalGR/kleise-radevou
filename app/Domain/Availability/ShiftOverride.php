<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * One member's day edited alone, a shift_overrides row as a value: the date and its windows,
 * none meaning not working that day. Each window is held as an AvailabilityRule on the date's
 * own weekday, so the expander reads it as it reads a pattern's, and a window that closes before
 * it opens is refused as a rule's is.
 */
final readonly class ShiftOverride
{
    /**
     * @param  list<AvailabilityRule>  $windows
     */
    private function __construct(
        public string $date,
        public array $windows,
    ) {}

    /**
     * @param  string  $date  YYYY-MM-DD
     * @param  list<array{WallClockTime, WallClockTime}>  $windows  each a start and an end
     */
    public static function of(string $date, array $windows): self
    {
        $weekday = CalendarDay::weekday($date);

        return new self($date, array_map(
            fn (array $window): AvailabilityRule => new AvailabilityRule($weekday, $window[0], $window[1]),
            $windows,
        ));
    }
}
