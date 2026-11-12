<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\HoldItem;
use App\Application\Repository\AvailabilityRepository;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\SlotSpan;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\Overridable;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;
use App\Domain\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A chain already decided, standing where it is going to stand: the reads the write protocol
 * takes of it, in one place, for the two writes that place one.
 *
 * A hold places a chain that did not exist and a move places one that did, and between the lock
 * and the insert the two run the same four questions over the items. Which staff-days do they
 * touch, for step 1 and for the warm that follows the commit. Which members and which window
 * does step 4 reap in. Can each member hold their range under the lock, which is the
 * availability step. And what do the prices come to, which is the container's total. Written
 * once because a second copy is a second chance for the window to be a little narrower on one
 * path than on the other, and the narrow one would let a lapsed hold stand.
 *
 * Two items that would contend with each other, on the same staff member with overlapping block
 * ranges, are refused as one is built: nothing in the store could ever accept them, and letting
 * them reach the insert would raise the exclusion constraint, which the protocol treats as a
 * hole in itself. The planner's boundary rule keeps a chain from ever tripping it, on either
 * path.
 *
 * It is not HoldPlan. A plan carries what a booking row copies from the chain, the status, the
 * hold class and the TTL, and a move writes none of those: it leaves the status, the class and
 * the expiry exactly as the lock found them. What both have is the items, which is what this
 * is, and HoldPlan holds one of these rather than repeating its rules.
 */
final readonly class PlacedChain
{
    /** @var non-empty-list<HoldItem> in segment order */
    public array $items;

    /**
     * @param  list<HoldItem>  $items  in segment order
     */
    public function __construct(array $items)
    {
        if ($items === []) {
            throw new InvalidArgumentException('A placed chain has at least one item.');
        }

        $blocks = array_map(fn (HoldItem $item): StaffBlock => $item->block(), $items);

        foreach ($blocks as $later => $block) {
            foreach (array_slice($blocks, 0, $later, true) as $earlier => $other) {
                if ($block->staffId === $other->staffId && $block->start < $other->end && $other->start < $block->end) {
                    throw new InvalidArgumentException(sprintf(
                        'Segments %d and %d occupy staff member %d at the same time.',
                        $earlier,
                        $later,
                        $block->staffId,
                    ));
                }
            }
        }

        $this->items = $items;
    }

    /**
     * Every item's contended range, in segment order, so a segment index into one is an index
     * into the other.
     *
     * @return non-empty-list<StaffBlock>
     */
    public function blocks(): array
    {
        return array_map(fn (HoldItem $item): StaffBlock => $item->block(), $this->items);
    }

    /**
     * The staff-days the chain touches, in the tenant's zone, sorted and de-duplicated: step 1
     * of the write protocol.
     */
    public function keys(DateTimeZone $zone): LockKeySet
    {
        return LockKeySet::forBlocks($this->blocks(), $zone);
    }

    /**
     * The staff members the items occupy, each once, in first-seen order.
     *
     * @return non-empty-list<int>
     */
    public function staffIds(): array
    {
        $staff = [];

        foreach ($this->blocks() as $block) {
            $staff[$block->staffId] = $block->staffId;
        }

        return array_values($staff);
    }

    /**
     * One range covering every block, the earliest start to the latest end: the window step 4
     * reaps expired holds in. A superset of the union of the ranges and that is fine, because
     * the reap only ever lets go of holds already past their expiry.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function window(): array
    {
        $blocks = $this->blocks();
        $start = $blocks[0]->start;
        $end = $blocks[0]->end;

        foreach ($blocks as $block) {
            $start = $block->start < $start ? $block->start : $start;
            $end = $block->end > $end ? $block->end : $end;
        }

        return [$start, $end];
    }

    /**
     * What the container is worth: the sum of the item prices, in the currency the first one
     * carries, so a chain that somehow mixed two is refused here rather than stored as a number
     * in neither.
     */
    public function total(): Money
    {
        return Money::sum($this->items[0]->price->currency, array_map(fn (HoldItem $item): Money => $item->price, $this->items));
    }

    /**
     * The availability step: the masks of every staff-day the chain touches, as the rows stand
     * under the lock, and every item's assigned member able to hold it there. The contended
     * range must be free of bookings and the service range inside the member's hours, on each
     * day either touches; a day neither touches asks nothing.
     *
     * A member who cannot hold the range is not a conflict: nobody else is in it, the tenant is
     * simply not open there, which is the same refusal the planner makes from the advisory
     * masks and is answered with the same code.
     *
     * @throws ChainUnavailable naming the segment whose member cannot hold its range
     */
    public function assertFits(AvailabilityRepository $availability, DateTimeZone $zone): void
    {
        foreach ($this->findings($availability, $zone) as [$segment, $kinds]) {
            if ($kinds === null || $kinds !== []) {
                throw ChainUnavailable::underLock($segment, $this->items[$segment]->block());
            }
        }
    }

    /**
     * The availability step for a move by the business: a contended range that is booked
     * refuses as assertFits refuses it, and a service range that crosses the member's hours or
     * a closure is answered rather than refused, each segment and kind once in segment order,
     * for the caller to warn about or to record as overridden.
     *
     * @return list<MaskCrossing>
     *
     * @throws ChainUnavailable naming the first segment whose contended range is booked
     */
    public function crossings(AvailabilityRepository $availability, DateTimeZone $zone): array
    {
        $crossings = [];

        foreach ($this->findings($availability, $zone) as [$segment, $kinds]) {
            if ($kinds === null) {
                throw ChainUnavailable::underLock($segment, $this->items[$segment]->block());
            }

            foreach ($kinds as $kind) {
                $crossings[$segment.':'.$kind->value] = new MaskCrossing($segment, $this->items[$segment]->staffId, $kind);
            }
        }

        return array_values($crossings);
    }

    /**
     * The business's crossings read off masks a caller already holds, the admin's advisory read
     * of where a booking may move: the same findings the write takes under the lock, over a set
     * read once for a run of starts. A contended range that is booked is another booking in the
     * way, which the write's overlap check answers before its masks are asked, so it is refused
     * here as that check refuses it.
     *
     * @return list<MaskCrossing>
     *
     * @throws SegmentConflict naming the first segment whose contended range is booked
     */
    public function crossingsOn(StaffDayMaskSet $set): array
    {
        $crossings = [];

        foreach ($this->findingsOn($set) as [$segment, $kinds]) {
            if ($kinds === null) {
                throw SegmentConflict::at($segment, $this->items[$segment]->block());
            }

            foreach ($kinds as $kind) {
                $crossings[$segment.':'.$kind->value] = new MaskCrossing($segment, $this->items[$segment]->staffId, $kind);
            }
        }

        return array_values($crossings);
    }

    /**
     * Every item against every day of the masks the chain's staff-days span, in segment order and
     * then day order.
     *
     * @return list<array{int, list<Overridable>|null}>
     */
    private function findings(AvailabilityRepository $availability, DateTimeZone $zone): array
    {
        [$firstDay, $dayCount] = self::daysOf($this->keys($zone));

        return $this->findingsOn($availability->masks($this->staffIds(), $firstDay, $dayCount));
    }

    /**
     * Every item against every day of the set: null where the contended span is booked, else
     * what of the open half the service span crosses. A day neither span touches asks nothing.
     *
     * @return list<array{int, list<Overridable>|null}>
     */
    private function findingsOn(StaffDayMaskSet $set): array
    {
        $findings = [];

        foreach ($this->items as $segment => $item) {
            foreach ($set->window->days as $day) {
                $masks = $set->on($item->staffId, $day->day);
                $findings[] = [$segment, $masks->booked(SlotSpan::outward($day, $item->blockStart, $item->blockEnd))
                    ? null
                    : $masks->crossed(SlotSpan::outward($day, $item->start, $item->end))];
            }
        }

        return $findings;
    }

    /**
     * The run of days a key set spans: its first day and how many days to the last, both
     * inclusive.
     *
     * @return array{string, int}
     */
    private static function daysOf(LockKeySet $keys): array
    {
        $days = array_map(fn (LockKey $key): string => $key->day, $keys->all());
        $first = min($days);
        $last = max($days);

        return [$first, CalendarDay::daysBetween($first, $last) + 1];
    }
}
