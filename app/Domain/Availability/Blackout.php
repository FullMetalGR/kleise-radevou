<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * One row of blackouts as a value: a dated override that removes availability. Tenant-wide
 * with no staff member, personal with one; the whole day with no window, that window with
 * one. The staff member stays on it, unlike a rule, because the builder is handed the day's
 * blackouts for the whole tenant and decides itself which ones reach the staff member it is
 * building for: a blackout naming another member changes nothing.
 */
final readonly class Blackout
{
    /**
     * @param  int|null  $staffId  null for tenant-wide
     * @param  string  $date  YYYY-MM-DD
     */
    public function __construct(
        public ?int $staffId,
        public string $date,
        public ?WallClockTime $start = null,
        public ?WallClockTime $end = null,
    ) {
        if ($staffId !== null && $staffId < 1) {
            throw InvalidBlackout::staffId($staffId);
        }

        CalendarDay::spelled($date);

        if ($start !== null && $end === null) {
            throw InvalidBlackout::startAlone($start);
        }

        if ($start === null && $end !== null) {
            throw InvalidBlackout::endAlone($end);
        }

        if ($start !== null && ! $start->isBefore($end)) {
            throw InvalidBlackout::window($start, $end);
        }
    }

    public function isWholeDay(): bool
    {
        return $this->start === null;
    }

    /**
     * Whether the blackout reaches this staff member on this day: its date, and either
     * tenant-wide or naming that member.
     */
    public function appliesTo(int $staffId, string $day): bool
    {
        return $this->date === $day && ($this->staffId === null || $this->staffId === $staffId);
    }
}
