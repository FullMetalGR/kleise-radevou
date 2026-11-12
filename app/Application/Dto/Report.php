<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;

/**
 * The Reports screen's figures over one range, computed when asked and stored nowhere.
 *
 * A booking is in the range by its first start, the earliest of its items' starts. statuses is
 * the count of bookings in each of the nine statuses, every status present and in the machine's
 * order. confirmed and completed are the two revenue lines: each the sum of total_minor over the
 * bookings of that status, one Money per currency those bookings carry, in the order of the
 * currency's code. noShows and completedCount are the no-show rate's two counts, the rate being
 * noShows over completedCount plus noShows.
 *
 * The four breakdowns are rows of a count and a revenue: bookings is how many bookings in the
 * range, in any status, the row holds, each counted once however many of its items the row
 * holds; revenue is the confirmed and completed bookings' share, per currency as above, through
 * the items' prices where the row is a service or a member and the booking's total where it is a
 * source or a channel. Every revenue list holds one line at least: where no confirmed or
 * completed booking carries a currency, it is zero in the tenant's currency, which is what an
 * empty range reads.
 *
 * services and staff are the catalogue's active rows and every other row an item in the range
 * names, in the catalogue's order, the implicit member named as the business; sources are the
 * three in the enum's order; channels the five in the enum's order and then null, the bookings
 * no tagged link brought.
 *
 * cancelledBy splits the cancelled bookings by who cancelled them, as their trail records it:
 * customer, business (a user or a support operator) and unrecorded (no trail row left); the three
 * sum to the cancelled count. Under Reports' filters every figure counts the matching bookings
 * alone, their revenue the matching items' prices.
 */
final readonly class Report
{
    /**
     * @param  array<string, int>  $statuses
     * @param  non-empty-list<Money>  $confirmed
     * @param  non-empty-list<Money>  $completed
     * @param  list<array{id: int, name: string, bookings: int, revenue: non-empty-list<Money>}>  $services
     * @param  list<array{id: int, name: string, bookings: int, revenue: non-empty-list<Money>}>  $staff
     * @param  list<array{source: string, bookings: int, revenue: non-empty-list<Money>}>  $sources
     * @param  list<array{channel: string|null, bookings: int, revenue: non-empty-list<Money>}>  $channels
     * @param  array{customer: int, business: int, unrecorded: int}  $cancelledBy
     */
    public function __construct(
        public array $statuses,
        public array $confirmed,
        public array $completed,
        public int $noShows,
        public int $completedCount,
        public array $services,
        public array $staff,
        public array $sources,
        public array $channels,
        public array $cancelledBy,
    ) {}
}
