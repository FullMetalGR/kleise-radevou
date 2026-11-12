<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use IteratorAggregate;

/**
 * Step 1 of the write protocol: every staff-day a booking's block ranges touch, in the tenant's
 * zone, sorted ascending by staff member then day, with no pair twice.
 *
 * The order is deadlock avoidance: two transactions locking the same pairs in the same order
 * queue instead of deadlocking. The de-duplication is what keeps the single-statement lock from
 * raising a cardinality violation on a chain with two items on one staff-day. Both happen on
 * construction and the constructor is private, so a caller cannot hold a set that is unsorted or
 * has a duplicate, whichever way it was built.
 *
 * The days come from the tenant's zone and not from UTC: a range that is one UTC day can be two
 * local days, and the mutex row is keyed by the local day the availability masks are built for.
 *
 * @implements IteratorAggregate<int, LockKey>
 */
final readonly class LockKeySet implements Countable, IteratorAggregate
{
    /**
     * @param  non-empty-list<LockKey>  $keys  sorted, unique
     */
    private function __construct(private array $keys) {}

    /**
     * The set for a booking's items: each block range yields every calendar day it touches in
     * the zone, paired with the item's staff member. A range crossing local midnight yields two
     * days; one ending exactly at midnight yields one, because the range is half-open.
     *
     * @param  iterable<StaffBlock>  $blocks
     */
    public static function forBlocks(iterable $blocks, DateTimeZone $zone): self
    {
        $keys = [];

        foreach ($blocks as $block) {
            foreach (self::daysTouched($block, $zone) as $day) {
                $keys[] = new LockKey($block->staffId, $day);
            }
        }

        return self::of(...$keys);
    }

    /**
     * The set of the given keys, in any order and with any repeats: sorted and de-duplicated
     * here. An empty set is refused, because a booking with no item has nothing to lock and the
     * lock statement has no form for zero rows.
     */
    public static function of(LockKey ...$keys): self
    {
        if ($keys === []) {
            throw new InvalidArgumentException('A key set names at least one staff-day.');
        }

        $sorted = array_values($keys);
        usort($sorted, fn (LockKey $a, LockKey $b): int => $a->compare($b));

        $unique = [$sorted[0]];

        foreach (array_slice($sorted, 1) as $key) {
            if (! $unique[array_key_last($unique)]->equals($key)) {
                $unique[] = $key;
            }
        }

        return new self($unique);
    }

    /**
     * @return non-empty-list<LockKey>
     */
    public function all(): array
    {
        return $this->keys;
    }

    /**
     * Whether the other set names the same staff-days. Both are sorted and de-duplicated by
     * construction, so naming the same pairs and being the same list are the same thing.
     *
     * What asks is the guarded transition: the set it locks was computed from a read taken
     * before the transaction, and the re-validation recomputes it from the items read back
     * under the lock. A difference means the booking's items moved while the lock was being
     * taken, so the rows held are not the rows that needed holding.
     */
    public function equals(self $other): bool
    {
        if (count($this->keys) !== count($other->keys)) {
            return false;
        }

        foreach ($this->keys as $index => $key) {
            if (! $key->equals($other->keys[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The set naming every staff-day either names, sorted and de-duplicated like any other.
     *
     * What asks is the reschedule, the one write that both frees a range and takes one: it
     * must hold the staff-days the booking is leaving and the staff-days it is moving to,
     * because it deletes items on the first and inserts them on the second, and a lock taken
     * on half of that protects half of it. The union is taken before anything is locked, so
     * the pairs are still sorted when the single-statement lock takes them and the ordering
     * that keeps two movers from deadlocking is the ordering of the whole set.
     */
    public function union(self $other): self
    {
        return self::of(...$this->keys, ...$other->keys);
    }

    /**
     * Whether every staff-day the other set names is one this set holds.
     *
     * The reschedule asks it where a guarded transition asks equals: the set locked is the
     * union of where the booking was and where it is going, so the items read back under the
     * lock name a subset of it rather than the whole of it, and a difference is still what says
     * the booking moved out from under the lock while the lock was being taken. Both sides are
     * sorted, so one pass down the two lists answers it.
     */
    public function covers(self $other): bool
    {
        $mine = 0;

        foreach ($other->keys as $key) {
            while ($mine < count($this->keys) && $this->keys[$mine]->compare($key) < 0) {
                $mine++;
            }

            if ($mine === count($this->keys) || ! $this->keys[$mine]->equals($key)) {
                return false;
            }
        }

        return true;
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * @return ArrayIterator<int, LockKey>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->keys);
    }

    /**
     * Every calendar day in the zone that the half-open range touches, ascending. The first is
     * the local date of the start. Each later day is touched when its local midnight, the
     * first instant of that day, falls strictly before the end. Each midnight is built from its
     * date rather than by adding a day to the previous instant: on a day whose midnight does
     * not exist the first instant is an hour later, and adding a day to that drifts.
     *
     * @return non-empty-list<string>
     */
    private static function daysTouched(StaffBlock $block, DateTimeZone $zone): array
    {
        $days = [$block->start->setTimezone($zone)->format('Y-m-d')];
        $calendar = new DateTimeZone('UTC');
        $date = new DateTimeImmutable($days[0], $calendar);

        while (true) {
            $date = $date->modify('+1 day');
            $midnight = new DateTimeImmutable($date->format('Y-m-d').' 00:00:00', $zone);

            if ($midnight >= $block->end) {
                return $days;
            }

            $days[] = $date->format('Y-m-d');
        }
    }
}
