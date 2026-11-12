<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\OverTime;
use App\Application\Dto\Report;
use App\Domain\Money\Money;

/**
 * The Reports screen's figures as the page receives them. Counts and amounts and nothing of any
 * customer, so the CONTACT rule has nothing to withhold and every viewer under viewBookings
 * receives the whole of it.
 *
 * An amount is its minor units beside its own currency, never a decimal and never the tenant's
 * default: the screen formats each line in the currency it carries at that currency's scale.
 * The statuses are a list in the machine's order, so the screen draws them in the order they
 * arrive. Occupancy by staff is minutes beside a percentage to one decimal, null where a member
 * offers and holds no minute, as the Occupancy tile's is (RangeTiles).
 */
final readonly class ReportPayload
{
    /**
     * @param  list<array{id: int, name: string, booked: int, available: int}>  $occupancy  Occupancy by staff, RangeFigures::occupancy
     * @return array{statuses: list<array{status: string, bookings: int}>, revenue: array{confirmed: list<array{currency: string, minor: int}>, completed: list<array{currency: string, minor: int}>}, no_show: array{no_show: int, completed: int}, services: list<array{id: int, name: string, bookings: int, revenue: list<array{currency: string, minor: int}>}>, staff: list<array{id: int, name: string, bookings: int, revenue: list<array{currency: string, minor: int}>}>, sources: list<array{source: string, bookings: int, revenue: list<array{currency: string, minor: int}>}>, channels: list<array{channel: string|null, bookings: int, revenue: list<array{currency: string, minor: int}>}>, cancelled_by: array{customer: int, business: int, unrecorded: int}, over_time: array{unit: string, rows: list<array{from: string, to: string, bookings: int, revenue: array{currency: string, minor: int}}>}, occupancy: list<array{id: int, name: string, booked_minutes: int, available_minutes: int, occupancy: float|null}>}
     */
    public static function of(Report $report, OverTime $overTime, array $occupancy): array
    {
        $statuses = [];

        foreach ($report->statuses as $status => $bookings) {
            $statuses[] = ['status' => (string) $status, 'bookings' => $bookings];
        }

        return [
            'statuses' => $statuses,
            'revenue' => ['confirmed' => self::lines($report->confirmed), 'completed' => self::lines($report->completed)],
            'no_show' => ['no_show' => $report->noShows, 'completed' => $report->completedCount],
            'services' => array_map(fn (array $row): array => [...$row, 'revenue' => self::lines($row['revenue'])], $report->services),
            'staff' => array_map(fn (array $row): array => [...$row, 'revenue' => self::lines($row['revenue'])], $report->staff),
            'sources' => array_map(fn (array $row): array => [...$row, 'revenue' => self::lines($row['revenue'])], $report->sources),
            'channels' => array_map(fn (array $row): array => [...$row, 'revenue' => self::lines($row['revenue'])], $report->channels),
            'cancelled_by' => $report->cancelledBy,
            'over_time' => [
                'unit' => $overTime->unit,
                'rows' => array_map(fn (array $row): array => [...$row, 'revenue' => RangeTiles::money($row['revenue'])], $overTime->rows),
            ],
            'occupancy' => array_map(fn (array $row): array => [
                'id' => $row['id'],
                'name' => $row['name'],
                'booked_minutes' => $row['booked'],
                'available_minutes' => $row['available'],
                'occupancy' => RangeTiles::percent($row['available'] === 0 ? null : (float) $row['booked'] / $row['available']),
            ], $occupancy),
        ];
    }

    /**
     * @param  list<Money>  $amounts
     * @return list<array{currency: string, minor: int}>
     */
    private static function lines(array $amounts): array
    {
        return array_map(RangeTiles::money(...), $amounts);
    }
}
