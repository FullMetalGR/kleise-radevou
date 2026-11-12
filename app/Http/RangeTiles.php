<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\FigureChange;
use App\Application\Dto\RangeFigures;
use App\Domain\Money\Money;

/**
 * The six tiles over a range as Home and Reports both receive them: the range by its word and two
 * dates, the period before by its two, and each tile with the value, the period before's value and
 * the change between them (FigureChange). Money is minor units beside its currency; occupancy a
 * percentage to one decimal, or null where no minute is offered, its change read on the unrounded
 * fractions. Counts and amounts, nothing of any customer, and no sentence: the screen words every
 * fact.
 */
final readonly class RangeTiles
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
     * }
     */
    public static function of(RangeFigures $figures): array
    {
        $now = $figures->now;
        $before = $figures->before;
        $previousRange = $figures->range->before();
        $count = fn (int $value, int $previous): array => ['value' => $value, 'previous' => $previous, 'change' => FigureChange::between($value, $previous)->toArray()];

        return [
            'range' => $figures->range->toArray(),
            'before' => ['from' => $previousRange->from, 'to' => $previousRange->to],
            'tiles' => [
                'bookings' => $count($now->bookings, $before->bookings),
                'revenue' => [
                    'value' => self::money($now->revenue),
                    'previous' => self::money($before->revenue),
                    'change' => FigureChange::between($now->revenue->minor, $before->revenue->minor)->toArray(),
                    'elsewhere' => array_map(self::money(...), $now->elsewhere),
                ],
                'occupancy' => [
                    'value' => self::percent($now->occupancy()),
                    'previous' => self::percent($before->occupancy()),
                    'change' => FigureChange::between($now->occupancy(), $before->occupancy())->toArray(),
                    'filled_minutes' => $now->filledMinutes,
                    'offered_minutes' => $now->offeredMinutes,
                ],
                'new_customers' => [...$count($now->newCustomers, $before->newCustomers), 'returning' => $now->customers - $now->newCustomers],
                'cancelled' => $count($now->cancelled, $before->cancelled),
                'no_shows' => $count($now->noShows, $before->noShows),
            ],
        ];
    }

    /**
     * @return array{currency: string, minor: int}
     */
    public static function money(Money $money): array
    {
        return ['currency' => $money->currency->code, 'minor' => $money->minor];
    }

    /** A fraction as a percentage to one decimal, null kept. */
    public static function percent(?float $fraction): ?float
    {
        return $fraction === null ? null : round($fraction * 100, 1);
    }
}
