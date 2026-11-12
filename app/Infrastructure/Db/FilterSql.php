<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Application\Dto\FigureFilter;

/**
 * Reports' Staff and Service filters as the figure readers' statements spell them, in one place
 * so the tiles, the cards, the tables and Over time narrow alike (RangeFigureRecords,
 * ReportFigures): an item matches where it is the chosen member's and the chosen service's, as
 * many of the two as are chosen; a booking counts where one of its items matches, and its
 * revenue is those items' own prices rather than its total. The ids are written into the text as
 * integers, which is all a filter can hold.
 */
final readonly class FilterSql
{
    /**
     * Whether an item, aliased item, matches the filter; true where there is none.
     */
    public static function item(FigureFilter $filter): string
    {
        $conditions = [];

        if ($filter->staffId !== null) {
            $conditions[] = sprintf('item.staff_id = %d', $filter->staffId);
        }

        if ($filter->serviceId !== null) {
            $conditions[] = sprintf('item.service_id = %d', $filter->serviceId);
        }

        return $conditions === [] ? 'true' : implode(' and ', $conditions);
    }

    /**
     * The schema's bookings by their first start (SqlText::bookingsByFirstStart), and under a
     * filter only those holding a matching item, each joined to those items' prices summed as
     * matched.minor.
     */
    public static function bookings(string $schema, FigureFilter $filter): string
    {
        $source = SqlText::bookingsByFirstStart($schema);

        if ($filter->isNone()) {
            return $source;
        }

        return $source.sprintf(
            ' join (select item.booking_id, sum(item.price_minor)::bigint as minor from %s.booking_items item where %s group by item.booking_id) matched on matched.booking_id = booking.id',
            $schema,
            self::item($filter),
        );
    }

    /**
     * A booking's revenue under the filter, over the from clause bookings() writes: its total, or
     * its matching items' prices.
     */
    public static function minor(FigureFilter $filter): string
    {
        return $filter->isNone() ? 'booking.total_minor' : 'matched.minor';
    }
}
