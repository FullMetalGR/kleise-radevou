<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Chain\Chain;
use InvalidArgumentException;

/**
 * What QueryAvailability takes: the whole chain as choices in order, the run of days asked
 * for, and whether one staff member should carry every segment. The window is DayWindow's,
 * the same one the per-booking read takes, and its rule is held there.
 *
 * The bounds are the ones the HTTP boundary enforces, held here too so that a query built
 * anywhere else is refused the same way: at least one item and at most the chain's cap.
 */
final readonly class AvailabilityQuery
{
    /**
     * The most days one query may ask for: to minus from.
     */
    public const int MAX_DAYS = DayWindow::MAX_DAYS;

    /**
     * @var non-empty-list<SegmentChoice>
     */
    public array $items;

    public DayWindow $window;

    public string $from;

    public string $to;

    /**
     * @param  list<SegmentChoice>  $items  in order, first to last
     * @param  string  $from  the first day, YYYY-MM-DD
     * @param  string  $to  the day after the last, YYYY-MM-DD
     */
    public function __construct(array $items, string $from, string $to, public bool $sameStaff = false)
    {
        if ($items === []) {
            throw new InvalidArgumentException('A query names at least one item.');
        }

        if (count($items) > Chain::MAX_SEGMENTS) {
            throw new InvalidArgumentException(sprintf('A query names at most %d items, not %d.', Chain::MAX_SEGMENTS, count($items)));
        }

        $this->items = $items;
        $this->window = new DayWindow($from, $to);
        $this->from = $this->window->from;
        $this->to = $this->window->to;
    }

    /**
     * Every day asked for, from from up to but excluding to, in order.
     *
     * @return non-empty-list<string>
     */
    public function days(): array
    {
        return $this->window->days();
    }

    public function dayCount(): int
    {
        return $this->window->dayCount();
    }

    /**
     * The services the items name, each once, in first-seen order.
     *
     * @return non-empty-list<int>
     */
    public function serviceIds(): array
    {
        $ids = [];

        foreach ($this->items as $item) {
            $ids[$item->serviceId] = $item->serviceId;
        }

        return array_values($ids);
    }
}
