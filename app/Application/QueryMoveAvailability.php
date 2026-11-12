<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\AvailableStarts;
use App\Application\Dto\DayWindow;
use App\Application\Dto\SoldItem;
use App\Application\Repository\AvailabilityLeavingOut;
use App\Application\Repository\ServiceRepository;
use App\Application\Repository\SoldChain;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\StaffBlock;

/**
 * The reschedule dialog's read, behind GET /bookings/{uuid}/availability: for the booking a
 * manage token admitted, the starts it could move to on each day of a window. Advisory, like
 * every availability read: the move is the only authority.
 *
 * The chain is the booking's own, read from its rows through the move's own pre-read and placed
 * on the catalog through SoldChainOnCatalog, the one derivation the move plans from: the same
 * services in the same order, each segment's member pinned to the one it was sold to with no
 * re-pick and no same-staff preference, and the geometry, the lead time and the horizon the
 * catalog gives now. What is offered is what the move plans.
 *
 * The booking's own items are left out of what counts as busy, because the move deletes them
 * before it checks for overlap: a read that counted them could never offer a nudge the move
 * would take. They are left out by the masks port, which rebuilds the staff-days they stand on
 * from the rows without them. The booking's current start is dropped from the answer whatever
 * the masks say, because once the booking is left out it always reads as free, and a move to
 * where the booking already is would be written and announced as a move.
 *
 * It judges nothing about status: it answers for any booking the credential admits, and the
 * write decides whether a move may happen. A chain the catalog can no longer place, a service
 * withdrawn or a sold member who no longer performs it, answers every day empty, as the public
 * read does for a service nobody performs; the move answers its own 409 when it is tried.
 */
final readonly class QueryMoveAvailability
{
    public function __construct(
        private SoldChain $sold,
        private ServiceRepository $services,
        private AvailabilityLeavingOut $availability,
        private TenantContext $tenant,
        private Clock $clock,
    ) {}

    /**
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     */
    public function query(string $uuid, DayWindow $days): AvailableStarts
    {
        $sold = $this->sold->of($uuid) ?? throw BookingUnknown::named($uuid);

        try {
            $chain = SoldChainOnCatalog::of($sold, $this->services)->chain;
        } catch (ChainUnavailable) {
            return AvailableStarts::none($days->days());
        }

        $zone = $this->tenant->zone();
        $window = new ChainWindow($chain, $days);
        $standsOn = LockKeySet::forBlocks(array_map(fn (SoldItem $item): StaffBlock => $item->block, $sold), $zone);

        return $window->starts(
            $this->availability->masksLeavingOut($uuid, $standsOn, $chain->staffIds(), $window->firstDay, $window->dayCount),
            $this->clock->now(),
            $zone,
        )->without($sold[0]->start);
    }
}
