<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\DayWindow;
use App\Application\Dto\MoveCandidate;
use App\Application\Dto\SoldItem;
use App\Application\Repository\AvailabilityLeavingOut;
use App\Application\Repository\SoldChain;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\StaffDayMaskSet;
use App\Domain\Booking\Actor;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;
use Closure;
use DateTimeImmutable;

/**
 * The admin's read of where a booking may move: the starts of a day it may take, each with its
 * crossings, and the verdict on one proposed start. Advisory, like every availability read: it
 * takes no lock, and the move under its locks stays the judge, so a start offered here may still be
 * refused by the write a moment later.
 *
 * It asks what the write asks, through the write's own parts rather than a second account of
 * them. The chain is placed by the planner the move plans with, HoldPlanner::replanning, with the
 * member override threaded through it and the business named as the one placing, so a start is
 * a candidate only where the planner would take it from the business (the grid and a start
 * already passed refuse it; the lead time and the horizon, which bind customers alone, do not)
 * and moves with the planner's rules whenever they change. Another booking in the way and the hours and closures
 * are PlacedChain's findings, the ones the write takes under the lock, read here off the masks
 * the customer's per-booking read reads, with the booking's own items left out of what is busy,
 * because the move deletes them before it checks for overlap. Those masks keep the member's
 * hours and what no closure covers apart, so a crossing is told as hours or a closure as the
 * write tells it.
 *
 * Another booking removes a start; the hours and the closures only mark it, since the business
 * may land there once it accepts the warning (19a). The booking's current start is left out of a
 * day's candidates where no segment changes member, as the customer's read leaves it out: a move
 * to where the booking already is would be written and announced as a move.
 */
final readonly class QueryBusinessMove
{
    public function __construct(
        private SoldChain $sold,
        private HoldPlanner $planner,
        private AvailabilityLeavingOut $availability,
        private TenantContext $tenant,
    ) {}

    /**
     * Every start on the tenant's grid that day the move could take, in time order.
     *
     * @param  string  $day  a tenant-local date, YYYY-MM-DD
     * @param  array<int, int>  $staff  a member by segment position, for the segments given to another member
     * @return list<MoveCandidate>
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws ChainUnavailable when the catalog can no longer place the chain, naming the segment
     * @throws StaffNotEligible when a segment is given to a member who does not perform its service
     * @throws NoSuchSegment when a member is given to a segment the chain does not have
     */
    public function candidates(string $uuid, string $day, array $staff): array
    {
        $sold = $this->sold->of($uuid) ?? throw BookingUnknown::named($uuid);
        $replanning = $this->planner->replanning($sold, $staff, Actor::Admin);
        $set = $this->masks($uuid, $sold, $replanning, $day);
        $geometry = $set->window->dayFor($day);
        $unchanged = self::keepsEveryMember($sold, $staff);
        $candidates = [];

        for ($index = 0; $index < $geometry->width; $index++) {
            $start = $geometry->instantAt($index);

            if ($unchanged && $start->getTimestamp() === $sold[0]->start->getTimestamp()) {
                continue;
            }

            try {
                $crossings = (new PlacedChain($replanning->at($start)))->crossingsOn($set);
            } catch (ChainUnavailable|SegmentConflict) {
                continue;
            }

            $candidates[] = new MoveCandidate($start, $crossings);
        }

        return $candidates;
    }

    /**
     * The verdict on one proposed start, asked in the write's order: the booking, the plan, the
     * guard the caller hands in where the write asks its policy, then another booking in the way,
     * then the crossings. Each refusal is the exception the write raises for the same request.
     *
     * @param  array<int, int>  $staff  a member by segment position
     * @param  Closure(): void  $guard  the status rule, asked where the write asks it, throwing as the write throws
     * @return list<MaskCrossing> what the move would cross there, none where it fits
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws ChainUnavailable when the planner refuses the start or the catalog the chain
     * @throws StaffNotEligible when a segment is given to a member who does not perform its service
     * @throws NoSuchSegment when a member is given to a segment the chain does not have
     * @throws SegmentConflict when another booking is in the way, naming the segment
     */
    public function verdict(string $uuid, DateTimeImmutable $start, array $staff, Closure $guard): array
    {
        $sold = $this->sold->of($uuid) ?? throw BookingUnknown::named($uuid);
        $replanning = $this->planner->replanning($sold, $staff, Actor::Admin);
        $placed = new PlacedChain($replanning->at($start));
        $guard();

        return $placed->crossingsOn($this->masks($uuid, $sold, $replanning, $start->setTimezone($this->tenant->zone())->format('Y-m-d')));
    }

    /**
     * The masks of every member the chain may touch over the window a day's starts need, the
     * booking's own staff-days rebuilt without it.
     *
     * @param  non-empty-list<SoldItem>  $sold
     */
    private function masks(string $uuid, array $sold, Replanning $replanning, string $day): StaffDayMaskSet
    {
        $window = new ChainWindow($replanning->chain, new DayWindow($day, CalendarDay::plusDays($day, 1)));
        $standsOn = LockKeySet::forBlocks(array_map(fn (SoldItem $item): StaffBlock => $item->block, $sold), $this->tenant->zone());

        return $this->availability->masksLeavingOut($uuid, $standsOn, $replanning->chain->staffIds(), $window->firstDay, $window->dayCount);
    }

    /**
     * @param  non-empty-list<SoldItem>  $sold
     * @param  array<int, int>  $staff
     */
    private static function keepsEveryMember(array $sold, array $staff): bool
    {
        foreach ($staff as $segment => $member) {
            if (($sold[$segment] ?? null)?->staffId !== $member) {
                return false;
            }
        }

        return true;
    }
}
