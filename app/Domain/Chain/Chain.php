<?php

declare(strict_types=1);

namespace App\Domain\Chain;

use App\Domain\Availability\Granularity;
use App\Domain\Booking\Actor;
use InvalidArgumentException;

/**
 * The chain a customer is asking about or holding: one or more segments in order, run forward
 * in time, and whether they would rather one staff member carried every segment. A single
 * service is a chain of one segment here, so the availability query and the hold have one shape
 * whatever the booking's size; the product's wording keeps the word for more than one segment
 * and the code does not, because the maths is the same and a second path for one segment would
 * be a second implementation.
 *
 * The same-staff preference is part of the chain rather than an argument to each question asked
 * of it, because it changes both answers the resolver gives, the feasible starts and the
 * assignment, and a chain described once should be answered consistently.
 *
 * The chain knows what it needs before any mask exists: which staff members it can touch, so
 * the caller knows whose rows to fetch; its horizon, so the caller knows how many days; and
 * each segment's offset in slots against a granularity, the sum over the earlier segments of
 * the duration and the processing gap. Buffers play no part in the offset: the next segment
 * starts when the previous duration and its gap end, and the buffers contend for the staff
 * member on their own through the per-segment rule.
 */
final readonly class Chain
{
    /**
     * The most segments one chain may carry: the cap the public service list publishes as
     * max_chain_length, that the availability and hold requests refuse past at the boundary,
     * and that the booking app's add button disappears at. One number, held here so the three
     * cannot disagree.
     */
    public const int MAX_SEGMENTS = 5;

    /**
     * The furthest ahead a service may be booked, in days from the tenant's today: the column's
     * CHECK holds every row to it and the service form refuses past it. The forward walk of
     * next_available_from reads no further than this many days whatever a chain's horizon says,
     * the belt beside the CHECK; the segments themselves refuse only a horizon below zero, so a
     * longer one reaching the walk from elsewhere is cut there and not refused. Held here so the
     * write and the walk cannot disagree; migration 000027 spells the CHECK's 365 itself, since a
     * shipped migration must not change when a constant does, and the admin's field spells its
     * own beside the form.
     */
    public const int MAX_HORIZON_DAYS = 365;

    /**
     * @var non-empty-list<ChainSegment>
     */
    public array $segments;

    /**
     * @param  list<ChainSegment>  $segments  in order, first to last
     */
    public function __construct(array $segments, public bool $sameStaff = false)
    {
        if ($segments === []) {
            throw new InvalidArgumentException('A chain is at least one segment long.');
        }

        if (count($segments) > self::MAX_SEGMENTS) {
            throw new InvalidArgumentException(sprintf('A chain is at most %d segments long, not %d.', self::MAX_SEGMENTS, count($segments)));
        }

        $this->segments = $segments;
    }

    /**
     * The largest lead time among the segments: a customer cannot book the chain closer than
     * its most demanding service allows.
     */
    public function leadTimeMin(): int
    {
        return max(array_map(fn (ChainSegment $segment): int => $segment->leadTimeMin, $this->segments));
    }

    /**
     * The smallest horizon among the segments: a customer cannot book the chain further ahead
     * than its most restrictive service allows.
     */
    public function horizonDays(): int
    {
        return min(array_map(fn (ChainSegment $segment): int => $segment->horizonDays, $this->segments));
    }

    /**
     * The lead time that binds whoever is placing the chain: the largest among the segments for a
     * customer, and none for the business, which may place it at any start not already passed.
     */
    public function leadTimeMinFor(Actor $by): int
    {
        return $by->isBoundByLeadTimeAndHorizon() ? $this->leadTimeMin() : 0;
    }

    /**
     * The horizon that binds whoever is placing the chain: the smallest among the segments for a
     * customer, and null, no horizon at all, for the business.
     */
    public function horizonDaysFor(Actor $by): ?int
    {
        return $by->isBoundByLeadTimeAndHorizon() ? $this->horizonDays() : null;
    }

    /**
     * How long the chain occupies its staff members from its start, in minutes: every duration
     * and the processing gap that follows it up to the last segment, then the last duration and
     * its cleanup buffer. The gap after the last segment is not in it, since the staff member
     * is free during a gap and nothing follows this one; the setup buffer of the first segment
     * is not in it either, since it falls before the start. This is what a caller building a
     * window widens it by, so that a start near the window's last day is not refused for the
     * chain running past the edge, which reads as busy.
     */
    public function spanMin(): int
    {
        $last = count($this->segments) - 1;
        $span = 0;

        foreach ($this->segments as $index => $segment) {
            $span += $segment->shape->durationMin;
            $span += $index === $last ? $segment->shape->bufferAfterMin : $segment->processingGapMin;
        }

        return $span;
    }

    /**
     * Every staff member the chain can touch, once and ascending: each segment's candidates,
     * the pick standing in for the eligible members where the customer made one.
     *
     * @return non-empty-list<int>
     */
    public function staffIds(): array
    {
        $ids = [];

        foreach ($this->segments as $segment) {
            foreach ($segment->candidates() as $staffId) {
                $ids[$staffId] = true;
            }
        }

        $list = array_keys($ids);
        sort($list);

        return $list;
    }

    /**
     * The staff members that are a candidate for every segment, ascending: the only ones the
     * same-staff form can offer. Empty when no one member can carry the whole chain.
     *
     * @return list<int>
     */
    public function commonStaffIds(): array
    {
        $common = $this->segments[0]->candidates();

        foreach (array_slice($this->segments, 1) as $segment) {
            $common = array_values(array_intersect($common, $segment->candidates()));
        }

        return $common;
    }

    /**
     * Each segment's offset from the chain's start, in slots at the granularity: the sum over
     * the segments before it of the duration and the processing gap, each rounded up to whole
     * slots on its own. The first segment's offset is zero.
     *
     * @return non-empty-list<int>
     */
    public function offsets(Granularity $granularity): array
    {
        $offsets = [];
        $offset = 0;

        foreach ($this->segments as $segment) {
            $offsets[] = $offset;
            $offset += $granularity->slotsFor($segment->shape->durationMin) + $granularity->slotsFor($segment->processingGapMin);
        }

        return $offsets;
    }
}
