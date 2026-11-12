<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\BookableService;
use App\Application\Dto\SoldItem;
use App\Application\Repository\ServiceRepository;
use App\Domain\Chain\Chain;

/**
 * What a booking was sold as, placed on the catalog as it stands now: the one derivation of the
 * chain a move plans and the per-booking availability read offers, written once so the two
 * cannot drift. A start the read offers is a start the move places with the same geometry only
 * because both build the chain here.
 *
 * The same services in the same order, each segment's member pinned to the one it was sold to
 * with no re-pick and no same-staff preference, because an engine that re-picked could offer a
 * time the customer's own manage view never showed them. The geometry, the lead time and the
 * horizon are the catalog's now: a move is a new placement, and the tenant's current arrangements
 * are what it has to fit.
 *
 * Two things the catalog can no longer place, and both refuse as unavailable naming the
 * segment: a service it no longer offers, and a sold member who no longer performs it, whether
 * deactivated or unassigned. The move answers that as its 409; the read answers every day
 * empty, as the public read does for a service nobody performs.
 *
 * A move by the business may give a segment to another member, keyed by the segment's position in
 * the chain because the items are deleted and inserted again on every move and their ids do not
 * survive one. That member is pinned in the sold one's place, and one who does not perform the
 * service, deactivated or unassigned included, is refused as the manual booking refuses a pick
 * off its list, StaffNotEligible naming the segment.
 */
final readonly class SoldChainOnCatalog
{
    /**
     * @param  non-empty-list<BookableService>  $services  by segment
     */
    private function __construct(public array $services, public Chain $chain) {}

    /**
     * @param  non-empty-list<SoldItem>  $sold  in chain order
     * @param  array<int, int>  $staff  a member by segment position for the segments the move gives to another member; empty for none
     *
     * @throws ChainUnavailable naming the first segment the catalog can no longer place
     * @throws StaffNotEligible naming the first segment given to a member who does not perform its service
     * @throws NoSuchSegment when a member is given to a segment the chain does not have
     */
    public static function of(array $sold, ServiceRepository $catalog, array $staff = []): self
    {
        foreach (array_keys($staff) as $segment) {
            if (! array_key_exists($segment, $sold)) {
                throw NoSuchSegment::in(count($sold), $segment);
            }
        }

        $bookable = $catalog->bookable(array_values(array_unique(array_map(fn (SoldItem $item): int => $item->serviceId, $sold))));
        $services = [];
        $segments = [];

        foreach ($sold as $index => $item) {
            $service = $bookable[$item->serviceId] ?? throw ChainUnavailable::serviceGone($index, $item->serviceId);

            $member = $staff[$index] ?? $item->staffId;

            if (! $service->performedBy($member)) {
                throw $member === $item->staffId ? ChainUnavailable::staffGone($index, $member) : StaffNotEligible::at($index, $member);
            }

            $services[] = $service;
            $segments[] = $service->segment($member);
        }

        return new self($services, new Chain($segments, false));
    }
}
