<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\BookableService;
use App\Application\Dto\HoldItem;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\SoldItem;
use App\Application\Repository\AvailabilityRepository;
use App\Application\Repository\ServiceRepository;
use App\Application\Repository\SettingsRepository;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\CalendarDay;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Booking\Actor;
use App\Domain\Chain\Chain;
use App\Domain\Chain\ChainResolver;
use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use DateInterval;
use DateTimeImmutable;

/**
 * Turns what a customer asked for into what the protocol writes: the services from the catalog,
 * the timeline of the chain from its start, the member for each segment, and the columns the
 * booking copies from the chain. Everything here is decided before any transaction opens and
 * nothing here is remade inside one: a member found busy under lock is a conflict, since
 * relocking a different key set would break the sorted order the protocol depends on.
 *
 * The chain is refused before any row is read when a service is unknown or inactive, a pick is
 * not among the eligible members, or a segment has nobody to perform it; and before any mask is
 * read when the prices mix two currencies, the start is off the tenant's grid, already passed,
 * or, for a customer, inside the largest lead time or past the smallest horizon. The lead time
 * and the horizon bind customers alone: the caller says who is placing, and one that says
 * nothing is bound as a customer is. They read the clock this planner was given; which items
 * block is the rows' business, read through the availability port as the advisory query reads
 * them.
 *
 * Assignment is a pure function: the eligible members per segment are those whose own start
 * mask is set at the segment's offset from the start, the customer's pick narrowing a segment
 * to one; among them the least loaded on the tenant-local day of the chain's start, the lowest
 * id breaking a tie, the loads read once and not adjusted between segments; under the
 * same-staff preference one member for every segment. No member for some segment is refused as
 * unavailable naming that segment, or segment zero under the preference.
 *
 * A move plans through here too, from what was sold rather than from what a customer chose: the
 * same services in the same order, each segment's member pinned with no re-pick, the geometry,
 * the lead time and the horizon the catalog gives now, and the prices the rows fixed at hold
 * time. Only the start is new. It is this planner rather than one of its own because what is
 * offered has to be what can be held, and two planners would be two ideas of where a chain
 * lands.
 *
 * The timeline: segment i starts at the chain's start plus the sum over the earlier segments of
 * the duration and the processing gap, each rounded up to whole slots; it ends a rounded
 * duration later; its processing gap, when it has one, ends a rounded gap after that; and it
 * occupies the member from a rounded buffer before to a rounded buffer after. Then the boundary
 * rule: where segments on one member overlap in block range, each boundary goes at the later
 * segment's start, and the time a boundary takes from one row the row beside it keeps, so the
 * member's occupied union is exactly what it was and the exclusion constraint stays a backstop.
 * Every instant is counted in elapsed seconds, never by moving a wall clock.
 */
final readonly class HoldPlanner
{
    private const int MINUTES_PER_DAY = 1440;

    public function __construct(
        private ServiceRepository $services,
        private AvailabilityRepository $availability,
        private SettingsRepository $settings,
        private TenantContext $tenant,
        private Clock $clock,
    ) {}

    /**
     * @param  Actor  $by  who is placing: the customer, bound by the lead time and the horizon, or the business, bound by neither
     *
     * @throws ServiceNotBookable for an unknown or inactive service
     * @throws StaffNotEligible for a pick outside the members who perform the service
     * @throws ChainUnavailable when the chain cannot start there, with the segment
     */
    public function plan(HoldRequest $request, Actor $by = Actor::Customer): HoldPlan
    {
        $services = $this->servicesOf($request);
        $this->assertOneCurrency($services);
        $chain = $this->chainOf($request, $services);
        $start = $request->start;

        $this->assertWithinRules($chain, $start, $by);
        [$assigned, $granularity] = $this->place($chain, $start);
        $items = self::itemsOf($services, $chain, $assigned, $start, $granularity, array_map(fn (BookableService $service): Money => $service->price, $services));
        $approving = array_values(array_filter($services, fn (BookableService $service): bool => $service->requiresApproval));
        $requiresApproval = $approving !== [];

        return new HoldPlan(
            customer: $request->customer,
            items: $items,
            status: $requiresApproval ? BookingStatus::AwaitingApproval : BookingStatus::Pending,
            holdClass: $requiresApproval ? HoldClass::Approval : HoldClass::Checkout,
            ttlMinutes: $this->ttlOf($approving),
            paymentMode: self::paymentModeOf($services),
            requiresApproval: $requiresApproval,
            onApprovalTimeout: ApprovalTimeout::acrossChain(array_map(fn (BookableService $service): ApprovalTimeout => $service->onApprovalTimeout, $approving)),
            intake: $request->intake,
        );
    }

    /**
     * The same chain at another start: what a move writes, decided before any transaction opens
     * and re-checked under the lock like any other placement.
     *
     * Everything but the start comes from what was sold. The services are the booking's own in its
     * own order; each segment's member is the one it was sold to, pinned with no re-pick and no
     * same-staff preference, because an engine that re-picked could offer a time the customer's own
     * manage view never showed them; and the prices are the rows', never the catalog's, so a tenant
     * who has changed their price list since cannot restate what a customer already agreed to pay
     * and the total does not move. The chain itself is SoldChainOnCatalog's, the one derivation the
     * per-booking availability read shares, so a start offered there is placed here with the same
     * geometry.
     *
     * The geometry is the catalog's as it stands now, and so are the lead time and the horizon
     * where they bind, a customer's move: a move is a new placement and the tenant's current
     * arrangements are what it has to fit.
     *
     * It reads no mask, and that is the difference from a hold rather than an omission. There is
     * nothing to assign, the member of every segment being the one it was sold to; and the
     * advisory masks would see the booking's own items, which is exactly the range it is leaving,
     * so a move of half an hour on one member would be refused before the transaction opened by
     * the very rows the transaction is about to delete. Whether the member is open to the new
     * range is decided under the lock, against masks rebuilt with the old items gone, and
     * answered with the same code either way.
     *
     * Two refusals come with the chain and both are unavailable naming the segment: a service
     * the catalog no longer offers, and a sold member who no longer performs it, whether they
     * were deactivated or unassigned. A hold answers those as a 404 and a 400, which are answers
     * to somebody choosing off a list; a booking already sold chose nothing, and what it needs
     * to be told is that this appointment cannot be moved. Cancelling still works either way.
     *
     * A move by the business may give segments to other members, by position in the chain: each
     * is pinned in the sold member's place, and the limits stay what they are for the manual
     * booking, which this planner also plans: the grid and the start already passed bind both,
     * the lead time and the horizon neither, since they bind customers alone.
     *
     * @param  non-empty-list<SoldItem>  $sold  in chain order
     * @param  array<int, int>  $staff  a member by segment position, for the segments given to another member
     * @param  Actor  $by  who is moving it, the customer unless the caller says the business
     * @return non-empty-list<HoldItem> in chain order
     *
     * @throws ChainUnavailable when the chain cannot be placed at that start, with the segment
     * @throws StaffNotEligible when a segment is given to a member who does not perform its service
     */
    public function replan(array $sold, DateTimeImmutable $start, array $staff = [], Actor $by = Actor::Customer): array
    {
        return $this->replanning($sold, $staff, $by)->at($start);
    }

    /**
     * The chain placed on the catalog once, answering a placement per start asked: replan for a
     * run of starts, the admin's read of where a booking may move, which asks every slot of a day
     * and must ask exactly what the move will ask. Placing on the catalog refuses as replan
     * refuses, before any start is asked; each start then refuses as replan refuses it.
     *
     * @param  non-empty-list<SoldItem>  $sold  in chain order
     * @param  array<int, int>  $staff  a member by segment position, for the segments given to another member
     * @param  Actor  $by  who is moving it, the customer unless the caller says the business
     *
     * @throws ChainUnavailable when the catalog can no longer place the chain, with the segment
     * @throws StaffNotEligible when a segment is given to a member who does not perform its service
     * @throws NoSuchSegment when a member is given to a segment the chain does not have
     */
    public function replanning(array $sold, array $staff = [], Actor $by = Actor::Customer): Replanning
    {
        $placed = SoldChainOnCatalog::of($sold, $this->services, $staff);
        $assigned = array_map(fn (SoldItem $item, int $segment): int => $staff[$segment] ?? $item->staffId, $sold, array_keys($sold));
        $prices = array_map(fn (SoldItem $item): Money => $item->price, $sold);

        return new Replanning($placed->chain, function (DateTimeImmutable $start) use ($placed, $assigned, $prices, $by): array {
            $this->assertWithinRules($placed->chain, $start, $by);

            $zone = $this->tenant->zone();
            $granularity = $this->settings->granularity();
            self::gridIndex(StaffDayGeometry::of($start->setTimezone($zone)->format('Y-m-d'), $zone, $granularity), $start);

            return self::itemsOf($placed->services, $placed->chain, $assigned, $start, $granularity, $prices);
        });
    }

    /**
     * The assignment on the masks as the advisory read sees them: the member for every segment,
     * and the grid the timeline is measured on. The window is the chain's start day with a day
     * either side and one more for every day the chain spans, so a segment running past local
     * midnight is asked about on the day it lands on.
     *
     * A segment with a pinned member narrows to that member, so a move whose sold member cannot
     * start their segment there is refused as unavailable naming it, exactly as a hold with a
     * pick is.
     *
     * @return array{non-empty-list<int>, Granularity} the member by segment, and the tenant's grid
     *
     * @throws ChainUnavailable naming the first segment nobody can start
     */
    private function place(Chain $chain, DateTimeImmutable $start): array
    {
        $zone = $this->tenant->zone();
        $startDay = $start->setTimezone($zone)->format('Y-m-d');
        $set = $this->availability->masks($chain->staffIds(), CalendarDay::plusDays($startDay, -1), 3 + intdiv($chain->spanMin() + self::MINUTES_PER_DAY - 1, self::MINUTES_PER_DAY));
        $window = $set->window;
        $geometry = $window->dayFor($startDay);
        $index = self::gridIndex($geometry, $start);
        $masksByStaff = [];

        foreach ($chain->staffIds() as $staffId) {
            $masksByStaff[$staffId] = $set->windowOf($staffId);
        }

        $resolver = new ChainResolver($chain, $masksByStaff);
        $assigned = $resolver->assign($window->offsetOf($startDay) + $index, $this->availability->loads($chain->staffIds(), $startDay));

        if ($assigned === null) {
            throw ChainUnavailable::nobodyAt(self::firstWithNobody($resolver, $chain, $window->offsetOf($startDay) + $index), $start);
        }

        return [$assigned, $window->granularity];
    }

    /**
     * The service of every item in item order, each resolved from the catalog, the unknown and
     * the inactive refused naming the item.
     *
     * @return non-empty-list<BookableService> by item
     */
    private function servicesOf(HoldRequest $request): array
    {
        $catalog = $this->services->bookable($request->serviceIds());
        $services = [];

        foreach ($request->items as $index => $item) {
            $services[] = $catalog[$item->serviceId] ?? throw ServiceNotBookable::at($index, $item->serviceId);
        }

        return $services;
    }

    /**
     * Every price in the tenant's currency, or the sum refuses the first that is not: a chain
     * mixing two currencies never reaches a transaction.
     *
     * @param  non-empty-list<BookableService>  $services
     */
    private function assertOneCurrency(array $services): void
    {
        Money::sum(Currency::of($this->tenant->currencyCode()), array_map(fn (BookableService $service): Money => $service->price, $services));
    }

    /**
     * @param  non-empty-list<BookableService>  $services  by item
     */
    private function chainOf(HoldRequest $request, array $services): Chain
    {
        $segments = [];

        foreach ($request->items as $index => $item) {
            $service = $services[$index];

            if ($item->staffId !== null && ! $service->performedBy($item->staffId)) {
                throw StaffNotEligible::at($index, $item->staffId);
            }

            if ($service->staffIds === []) {
                throw ChainUnavailable::nobodyAt($index, $request->start);
            }

            $segments[] = $service->segment($item->staffId);
        }

        return new Chain($segments, $request->sameStaff);
    }

    /**
     * The lead time and the horizon: the start no earlier than now plus the largest lead time in
     * elapsed minutes, and on a tenant-local date no later than the tenant's today plus the
     * smallest horizon in calendar days, where they bind, which the chain answers for whoever is
     * placing. A start before now is refused first and named as passed, apart from the lead time,
     * since it stays refused for everyone whatever the lead time binds.
     */
    private function assertWithinRules(Chain $chain, DateTimeImmutable $start, Actor $by): void
    {
        $now = $this->clock->now();

        if ($start < $now) {
            throw ChainUnavailable::inThePast($now);
        }

        $earliest = $now->add(new DateInterval(sprintf('PT%dM', $chain->leadTimeMinFor($by))));

        if ($start < $earliest) {
            throw ChainUnavailable::insideLeadTime($earliest);
        }

        $horizon = $chain->horizonDaysFor($by);

        if ($horizon === null) {
            return;
        }

        $zone = $this->tenant->zone();
        $lastDay = CalendarDay::plusDays($now->setTimezone($zone)->format('Y-m-d'), $horizon);

        if (CalendarDay::compare($start->setTimezone($zone)->format('Y-m-d'), $lastDay) > 0) {
            throw ChainUnavailable::pastHorizon($lastDay);
        }
    }

    /**
     * The slot the start falls on, refused unless it is exactly a slot's start: the masks
     * speak in slots, and a start between two would be answered for the slot before it.
     */
    private static function gridIndex(StaffDayGeometry $geometry, DateTimeImmutable $start): int
    {
        $index = $geometry->indexAtOrBefore($start);

        if ($index === $geometry->width || $geometry->instantAt($index)->getTimestamp() !== $start->getTimestamp() || (int) $start->format('u') !== 0) {
            throw ChainUnavailable::offGrid($start, $geometry->granularity->minutes);
        }

        return $index;
    }

    private static function firstWithNobody(ChainResolver $resolver, Chain $chain, int $start): int
    {
        if ($chain->sameStaff) {
            return 0;
        }

        foreach ($resolver->eligibleAt($start) as $segment => $members) {
            if ($members === []) {
                return $segment;
            }
        }

        return 0;
    }

    /**
     * The items along the timeline, then the boundary rule over the segments of each member.
     *
     * @param  non-empty-list<BookableService>  $services  by item
     * @param  non-empty-list<int>  $assigned  by segment
     * @param  non-empty-list<Money>  $prices  by item: the catalog's for a hold, the row's for a move
     * @return non-empty-list<HoldItem>
     */
    private static function itemsOf(array $services, Chain $chain, array $assigned, DateTimeImmutable $start, Granularity $granularity, array $prices): array
    {
        $step = $granularity->minutes * 60;
        $offsets = $chain->offsets($granularity);
        $starts = [];
        $ends = [];
        $blockStarts = [];
        $blockEnds = [];
        $processing = [];

        foreach ($services as $index => $service) {
            $shape = $service->shape;
            $segmentStart = self::later($start, $offsets[$index] * $step);
            $end = self::later($segmentStart, $granularity->slotsFor($shape->durationMin) * $step);
            $starts[] = $segmentStart;
            $ends[] = $end;
            $blockStarts[] = self::later($segmentStart, -$granularity->slotsFor($shape->bufferBeforeMin) * $step);
            $blockEnds[] = self::later($end, $granularity->slotsFor($shape->bufferAfterMin) * $step);
            $processing[] = $service->processingGapMin > 0 ? self::later($end, $granularity->slotsFor($service->processingGapMin) * $step) : null;
        }

        $blocks = self::boundaries($assigned, $starts, $blockStarts, $blockEnds);
        $items = [];

        foreach ($services as $index => $service) {
            $items[] = new HoldItem(
                staffId: $assigned[$index],
                serviceId: $service->id,
                start: $starts[$index],
                end: $ends[$index],
                blockStart: $blocks[$index]->start,
                blockEnd: $blocks[$index]->end,
                processingEndsAt: $processing[$index],
                price: $prices[$index],
            );
        }

        return $items;
    }

    /**
     * The boundary rule, run over each stretch of time one member is occupied for: the stretch
     * is cut at the start of each of its later segments, and its earliest block start and
     * latest block end stay on its two ends. So the rows of a stretch meet without overlapping,
     * each still covers its own service time, and the member's occupied union is exactly what
     * it was. Cutting alone would lose time whenever a block outreaches its neighbour: a
     * cleanup running past the whole block after it, or a setup reaching back past the whole
     * block before it. A segment whose block touches nothing is a stretch of one and keeps both
     * its buffers whole.
     *
     * @param  non-empty-list<int>  $assigned  by segment
     * @param  non-empty-list<DateTimeImmutable>  $starts  by segment
     * @param  non-empty-list<DateTimeImmutable>  $blockStarts  by segment
     * @param  non-empty-list<DateTimeImmutable>  $blockEnds  by segment
     * @return non-empty-list<BlockRange> by segment
     */
    private static function boundaries(array $assigned, array $starts, array $blockStarts, array $blockEnds): array
    {
        foreach (self::runsOf($assigned, $blockStarts, $blockEnds) as $run) {
            $segments = $run['segments'];
            $last = count($segments) - 1;

            foreach ($segments as $position => $segment) {
                $blockStarts[$segment] = $position === 0 ? $run['start'] : $starts[$segment];
                $blockEnds[$segment] = $position === $last ? $run['end'] : $starts[$segments[$position + 1]];
            }
        }

        $blocks = [];

        foreach (array_keys($starts) as $segment) {
            $blocks[] = new BlockRange($blockStarts[$segment], $blockEnds[$segment]);
        }

        return $blocks;
    }

    /**
     * The segments in chain order, grouped into the stretches of time each member is occupied
     * for: a segment joins the stretch its member has open when their blocks overlap, and a
     * segment whose setup reaches back over an earlier stretch of that member takes it in too,
     * since all of it is then one stretch. Two members never share one, and neither do two
     * segments of one member with free time between them. Every stretch holds its member's
     * segments in chain order, which is why cutting it at their starts tiles it: a segment
     * between two of a stretch always belongs to that stretch, its own service time lying
     * inside the span the two cover.
     *
     * @param  non-empty-list<int>  $assigned  by segment
     * @param  non-empty-list<DateTimeImmutable>  $blockStarts  by segment
     * @param  non-empty-list<DateTimeImmutable>  $blockEnds  by segment
     * @return list<array{segments: non-empty-list<int>, start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    private static function runsOf(array $assigned, array $blockStarts, array $blockEnds): array
    {
        $byStaff = [];

        foreach ($assigned as $segment => $staffId) {
            $open = ['segments' => [$segment], 'start' => $blockStarts[$segment], 'end' => $blockEnds[$segment]];
            $stretches = $byStaff[$staffId] ?? [];

            while ($stretches !== [] && $stretches[count($stretches) - 1]['end'] > $open['start']) {
                $previous = array_pop($stretches);
                $open = [
                    'segments' => [...$previous['segments'], ...$open['segments']],
                    'start' => min($previous['start'], $open['start']),
                    'end' => max($previous['end'], $open['end']),
                ];
            }

            $stretches[] = $open;
            $byStaff[$staffId] = $stretches;
        }

        $runs = [];

        foreach ($byStaff as $stretches) {
            foreach ($stretches as $stretch) {
                $runs[] = $stretch;
            }
        }

        return $runs;
    }

    /**
     * An instant a number of seconds along the timeline, in the instant's own zone. Timestamp
     * arithmetic, so a clock change between the two moves nothing; the microseconds are zero
     * on every instant here, since the start was checked to be on the grid.
     */
    private static function later(DateTimeImmutable $instant, int $seconds): DateTimeImmutable
    {
        return $instant->setTimestamp($instant->getTimestamp() + $seconds);
    }

    /**
     * The checkout TTL of the settings row, or for a chain requiring approval the smallest
     * approval window among the segments that require it, each the service's own hours or the
     * settings row's default: the most demanding window is the one the customer is promised.
     *
     * @param  list<BookableService>  $approving
     */
    private function ttlOf(array $approving): int
    {
        $ttls = $this->settings->holdTtls();

        if ($approving === []) {
            return $ttls->checkoutMinutes;
        }

        return min(array_map(fn (BookableService $service): int => $ttls->approvalMinutes($service->approvalHoldHours), $approving));
    }

    /**
     * Online if any segment's service is online, else onsite if any is onsite, else free: the
     * chain is paid the way its most demanding segment is.
     *
     * @param  non-empty-list<BookableService>  $services
     */
    private static function paymentModeOf(array $services): PaymentMode
    {
        $modes = array_map(fn (BookableService $service): PaymentMode => $service->paymentMode, $services);

        if (in_array(PaymentMode::Online, $modes, true)) {
            return PaymentMode::Online;
        }

        return in_array(PaymentMode::Onsite, $modes, true) ? PaymentMode::Onsite : PaymentMode::Free;
    }
}
