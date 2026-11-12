<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\RangeFigures;

/**
 * Home's range_figures prop: the range, the period before and the six tiles as RangeTiles shapes
 * them for Home and Reports alike, and the two Top tables, each row's revenue minor units beside
 * its currency.
 */
final readonly class HomeRangeFigures
{
    /**
     * @return array{
     *     range: array{word: string, from: string, to: string},
     *     before: array{from: string, to: string},
     *     tiles: array{
     *         bookings: array{value: int, previous: int, change: array{kind: string, percent: int|null}},
     *         revenue: array{value: array{currency: string, minor: int}, previous: array{currency: string, minor: int}, change: array{kind: string, percent: int|null}, elsewhere: list<array{currency: string, minor: int}>},
     *         occupancy: array{value: float|null, previous: float|null, change: array{kind: string, percent: int|null}, filled_minutes: int, offered_minutes: int},
     *         new_customers: array{value: int, previous: int, change: array{kind: string, percent: int|null}, returning: int},
     *         cancelled: array{value: int, previous: int, change: array{kind: string, percent: int|null}},
     *         no_shows: array{value: int, previous: int, change: array{kind: string, percent: int|null}},
     *     },
     *     top_services: list<array{id: int, name: string, bookings: int, revenue: array{currency: string, minor: int}}>,
     *     top_staff: list<array{id: int, name: string, business: bool, bookings: int, revenue: array{currency: string, minor: int}}>,
     * }
     */
    public static function of(RangeFigures $figures): array
    {
        return [
            ...RangeTiles::of($figures),
            'top_services' => array_map(fn (array $row): array => [...$row, 'revenue' => RangeTiles::money($row['revenue'])], $figures->topServices),
            'top_staff' => array_map(fn (array $row): array => [...$row, 'revenue' => RangeTiles::money($row['revenue'])], $figures->topStaff),
        ];
    }
}
