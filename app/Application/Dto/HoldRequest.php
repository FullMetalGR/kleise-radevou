<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Chain\Chain;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What a customer asks for: who they are, the chain as choices in order, each a service and a
 * staff pick or null, the instant the chain should start, what the booking carries from the
 * surface it came through, and whether one staff member should carry every segment. Nothing
 * here is resolved: the planner reads the catalog, decides every item and re-checks its
 * decision under lock, and carries the intake to the write as it was given.
 *
 * The bounds are the ones the HTTP boundary enforces, held here too so that a request built
 * anywhere else is refused the same way: at least one item and at most the chain's cap.
 */
final readonly class HoldRequest
{
    /** @var non-empty-list<SegmentChoice> in order, first to last */
    public array $items;

    /**
     * @param  list<SegmentChoice>  $items  in order, first to last
     */
    public function __construct(
        public Customer $customer,
        array $items,
        public DateTimeImmutable $start,
        public Intake $intake,
        public bool $sameStaff = false,
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('A hold names at least one item.');
        }

        if (count($items) > Chain::MAX_SEGMENTS) {
            throw new InvalidArgumentException(sprintf('A hold names at most %d items, not %d.', Chain::MAX_SEGMENTS, count($items)));
        }

        $this->items = $items;
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
