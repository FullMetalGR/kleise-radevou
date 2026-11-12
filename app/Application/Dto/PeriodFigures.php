<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;

/**
 * One period's tallies for Home's tiles, every booking in the period by its first start.
 *
 * bookings and revenue are the confirmed and completed bookings: revenue their totals in the
 * tenant's currency, elsewhere the same in each other currency a counted booking carries, in the
 * order of the code, none zero. cancelled and noShows count their own status. newCustomers and
 * customers count addresses with a counted booking in the period, newCustomers those whose first
 * counted booking ever starts in it. filledMinutes is the counted bookings' items' service time,
 * start to end; offeredMinutes the staff's hours less closures (OfferedMinutes) plus the filled
 * minutes outside them (Occupancy), so occupancy never passes one.
 */
final readonly class PeriodFigures
{
    /**
     * @param  list<Money>  $elsewhere
     */
    public function __construct(
        public int $bookings,
        public Money $revenue,
        public array $elsewhere,
        public int $cancelled,
        public int $noShows,
        public int $newCustomers,
        public int $customers,
        public int $filledMinutes,
        public int $offeredMinutes,
    ) {}

    /** Occupancy as a fraction, or null where no minute is offered. */
    public function occupancy(): ?float
    {
        return $this->offeredMinutes === 0 ? null : $this->filledMinutes / $this->offeredMinutes;
    }
}
