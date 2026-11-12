<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;

/**
 * Home's figures over a range: the range's tallies and the period before's, and the range's Top
 * services and Top staff.
 *
 * A Top row counts the confirmed and completed bookings by first start, as the tiles do: bookings
 * is how many hold an item of the row, each once; revenue the row's items' own prices in the
 * tenant's currency. business marks the implicit member, named as the business. At most five
 * rows, by revenue, then bookings, then name.
 *
 * occupancy is the range's Occupancy by staff: per member, in the catalogue's order, the minutes
 * booked and the minutes available, the hours offered plus the booked minutes outside them, so
 * booked never passes available.
 */
final readonly class RangeFigures
{
    /**
     * @param  list<array{id: int, name: string, bookings: int, revenue: Money}>  $topServices
     * @param  list<array{id: int, name: string, business: bool, bookings: int, revenue: Money}>  $topStaff
     * @param  list<array{id: int, name: string, booked: int, available: int}>  $occupancy
     */
    public function __construct(
        public FigureRange $range,
        public PeriodFigures $now,
        public PeriodFigures $before,
        public array $topServices,
        public array $topStaff,
        public array $occupancy = [],
    ) {}
}
