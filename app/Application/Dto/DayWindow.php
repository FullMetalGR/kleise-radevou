<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Availability\CalendarDay;
use InvalidArgumentException;

/**
 * The run of tenant-local days an availability read answers: the first day asked for and the
 * day after the last, to exclusive. Both reads take it the same way, the public one beside its
 * chain of choices and the per-booking one alone, because the chain there is the booking's own;
 * one rule, one cap and one set of sentences at the boundary, and this is where the rule is
 * held for a window built anywhere else.
 *
 * A window is at least one day and at most MAX_DAYS, two months of date strip. The days are
 * calendar dates spelled as every day is; a window never holds an instant, because which
 * instants a day holds is the tenant's zone's business.
 */
final readonly class DayWindow
{
    /**
     * The most days one read may ask for: to minus from.
     */
    public const int MAX_DAYS = 62;

    public string $from;

    public string $to;

    /**
     * @param  string  $from  the first day, YYYY-MM-DD
     * @param  string  $to  the day after the last, YYYY-MM-DD
     */
    public function __construct(string $from, string $to)
    {
        $from = CalendarDay::spelled($from);
        $to = CalendarDay::spelled($to);

        if (CalendarDay::compare($from, $to) >= 0) {
            throw new InvalidArgumentException(sprintf('A query ends after it starts, not from %s to %s.', $from, $to));
        }

        $days = CalendarDay::daysBetween($from, $to);

        if ($days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('A query spans at most %d days, not %d.', self::MAX_DAYS, $days));
        }

        $this->from = $from;
        $this->to = $to;
    }

    /**
     * Every day asked for, from from up to but excluding to, in order.
     *
     * @return non-empty-list<string>
     */
    public function days(): array
    {
        $days = [$this->from];

        for ($later = 1; $later < $this->dayCount(); $later++) {
            $days[] = CalendarDay::plusDays($this->from, $later);
        }

        return $days;
    }

    public function dayCount(): int
    {
        return CalendarDay::daysBetween($this->from, $this->to);
    }
}
