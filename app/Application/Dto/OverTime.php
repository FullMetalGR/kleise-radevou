<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;

/**
 * Reports' Over time: the range's buckets (FigureRange::buckets), each two of the tenant's dates
 * with the last inclusive, the confirmed and completed bookings whose first start falls in it,
 * and their revenue in the tenant's currency, under the screen's filters. The buckets sum to the
 * Bookings and Revenue tiles. unit is day, week or month, which the headings follow.
 */
final readonly class OverTime
{
    /**
     * @param  'day'|'week'|'month'  $unit
     * @param  list<array{from: string, to: string, bookings: int, revenue: Money}>  $rows
     */
    public function __construct(public string $unit, public array $rows) {}
}
