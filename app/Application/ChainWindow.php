<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\AvailableStarts;
use App\Application\Dto\DayWindow;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Booking\Actor;
use App\Domain\Chain\Chain;
use App\Domain\Chain\ChainResolver;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A chain asked about over a run of days: the masks window the question needs, and the answer
 * once the masks are in. The two availability reads share it, the public one for a chain of
 * choices and the per-booking one for the chain a booking was sold as, so the widening and the
 * rules a customer may not break are written once.
 *
 * The masks are read over a window wider than the days asked for: one day before, for a setup
 * buffer on the first day that begins the day before, and past the last day by one day plus
 * the chain's span rounded up to whole days, because the trailing window edge reads as busy
 * and a chain starting late on the last day runs past it. Per staff member the days
 * concatenate into one pair of window masks, the resolver answers the feasible start mask over
 * the whole window, and the generator drops what a customer may not take: starts inside the
 * largest lead time from now, and days past the smallest horizon from the tenant's today,
 * which is now read in the tenant's zone. Asked for the business, it drops only the starts
 * already passed. Only the days asked for are answered.
 */
final readonly class ChainWindow
{
    private const int MINUTES_PER_DAY = 1440;

    /**
     * The first day of the masks window, the day before the first asked for.
     */
    public string $firstDay;

    /**
     * How many days the masks window runs.
     */
    public int $dayCount;

    public function __construct(public Chain $chain, public DayWindow $days)
    {
        $this->firstDay = CalendarDay::plusDays($days->from, -1);
        $this->dayCount = 1 + $days->dayCount() + 1 + intdiv($chain->spanMin() + self::MINUTES_PER_DAY - 1, self::MINUTES_PER_DAY);
    }

    /**
     * The starts on every day asked for, from masks read over this window for every staff
     * member the chain can touch, as whoever is asking may take them, a customer unless the
     * caller says the business.
     */
    public function starts(StaffDayMaskSet $set, DateTimeImmutable $now, DateTimeZone $zone, Actor $by = Actor::Customer): AvailableStarts
    {
        $masksByStaff = [];

        foreach ($this->chain->staffIds() as $staffId) {
            $masksByStaff[$staffId] = $set->windowOf($staffId);
        }

        $generator = (new ChainResolver($this->chain, $masksByStaff))->slotGenerator($now, $now->setTimezone($zone)->format('Y-m-d'), $by);
        $byDay = [];

        foreach ($this->days->days() as $day) {
            $byDay[$day] = $generator->startsOn($day);
        }

        return new AvailableStarts($byDay);
    }
}
