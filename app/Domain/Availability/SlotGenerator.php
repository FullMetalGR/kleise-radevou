<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The start times a customer can be shown, from a start mask over a window: the set slots as
 * instants in the tenant's zone, in order, less the ones a customer may not take. Two rules
 * drop a start, both from the service row. A start earlier than now plus the lead time is
 * gone, the lead time counted in elapsed minutes, so a lead time of zero still drops the past.
 * A start on a tenant-local date later than today plus the horizon is gone, the horizon
 * counted in calendar days and not in hours from now, because the date strip is built from
 * days and today is the tenant's today. No horizon, null, drops no date: the business is bound
 * by neither rule, and is given a lead time of zero and no horizon.
 *
 * Now and today are inputs. Nothing here reads a clock, and nothing here decides what date an
 * instant falls on: the window's days already know, and a start's date is the day whose
 * slice of the mask it sits in. It answers the starts of one date, for the grid, and the
 * dates with at least one start, for the date strip. Every start out is an instant carrying
 * the tenant's zone; the dates are spelled as every day is, and strings for the wire are the
 * HTTP layer's to make.
 */
final readonly class SlotGenerator
{
    private DateTimeImmutable $earliest;

    /** The last tenant-local date a start may fall on, or null where no horizon binds. */
    private ?string $lastDay;

    /**
     * @param  string  $today  the tenant's today, YYYY-MM-DD
     * @param  int|null  $horizonDays  null where no horizon binds
     */
    public function __construct(
        private WindowGeometry $window,
        private FreeBusyMask $starts,
        DateTimeImmutable $now,
        string $today,
        int $leadTimeMin,
        ?int $horizonDays,
    ) {
        if ($starts->width !== $window->width) {
            throw new InvalidArgumentException(sprintf('A start mask is the width of its window, %d, not %d.', $window->width, $starts->width));
        }

        if ($leadTimeMin < 0) {
            throw new InvalidArgumentException(sprintf('A lead time is a number of minutes at or above zero, not %d.', $leadTimeMin));
        }

        if ($horizonDays !== null && $horizonDays < 0) {
            throw new InvalidArgumentException(sprintf('A horizon is a number of days at or above zero, not %d.', $horizonDays));
        }

        // An interval added moves along the timeline. modify('+N minutes') moves the wall clock,
        // which on a clock-change day is an hour more or less than N minutes of elapsed time.
        $this->earliest = $now->add(new DateInterval(sprintf('PT%dM', $leadTimeMin)));
        // Today is read whether or not a horizon binds, so a today that is not a day is refused
        // either way.
        $lastDay = CalendarDay::plusDays($today, $horizonDays ?? 0);
        $this->lastDay = $horizonDays === null ? null : $lastDay;
    }

    /**
     * The starts on one date of the window a customer may take, ascending, as instants in the
     * tenant's zone. Empty for a date past the horizon or whose starts are all in the past.
     *
     * @return list<DateTimeImmutable>
     */
    public function startsOn(string $day): array
    {
        $geometry = $this->window->dayFor($day);

        if ($this->lastDay !== null && CalendarDay::compare($day, $this->lastDay) > 0) {
            return [];
        }

        $offset = $this->window->offsetOf($day);
        $starts = [];

        for ($index = 0; $index < $geometry->width; $index++) {
            if (! $this->starts->isSet($offset + $index)) {
                continue;
            }

            $instant = $geometry->instantAt($index);

            if ($instant < $this->earliest) {
                continue;
            }

            $starts[] = $instant;
        }

        return $starts;
    }

    /**
     * The dates of the window with at least one start a customer may take, in date order.
     *
     * @return list<string>
     */
    public function datesWithStarts(): array
    {
        $dates = [];

        foreach ($this->window->days as $day) {
            if ($this->startsOn($day->day) !== []) {
                $dates[] = $day->day;
            }
        }

        return $dates;
    }
}
