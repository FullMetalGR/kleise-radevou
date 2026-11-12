<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\AvailabilityQuery;
use App\Application\Dto\AvailableStarts;
use App\Application\Dto\DayWindow;
use App\Application\Repository\AvailabilityRepository;
use App\Application\Repository\ServiceRepository;
use App\Domain\Availability\CalendarDay;
use App\Domain\Booking\Actor;
use App\Domain\Chain\Chain;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The availability query on real rows, behind GET /availability and the admin's one JSON
 * endpoint: for a chain of choices over a run of tenant-local days, the instants at which the
 * whole chain can start on each of them. Advisory: the hold is the only authority.
 *
 * The chain is built from the catalog: every service resolved, an unknown or inactive one
 * refused, each segment's eligible members the service's active assigned staff with the
 * implicit member among them, and a pick refused unless it is one of them. The HTTP boundary
 * has already checked both and answered politely; a refusal here is a bug or a catalog that
 * changed between the two reads, and is thrown as one. A service nobody performs makes the
 * chain unbookable rather than malformed, and the answer is every day empty.
 *
 * The window, the widening and the rules a customer may not break are ChainWindow's, shared
 * with the per-booking read. Asked for the business, the admin's wizard, the answer keeps the
 * starts inside the lead time and the days past the horizon, which bind customers alone; a
 * caller that names nobody is answered as a customer is.
 *
 * Past an empty window it can say where the chain next starts, next_available_from: the walk
 * reads forward from the window's end in windows of seven days, each read through ChainWindow
 * and the repository exactly as an answer is, so the masks are cached as every read's are, and
 * it stops at the first day holding a start on the start interval or at the smallest horizon
 * across the chain, and never reads more than 365 days. Its cost is bounded by the smaller of
 * the two.
 */
final readonly class QueryAvailability
{
    /**
     * How many days one read of the forward walk covers: the booking app's week.
     */
    private const int WALK_DAYS = 7;

    public function __construct(
        private ServiceRepository $services,
        private AvailabilityRepository $availability,
        private TenantContext $tenant,
        private Clock $clock,
    ) {}

    public function query(AvailabilityQuery $query, Actor $by = Actor::Customer): AvailableStarts
    {
        $chain = $this->chainOf($query);

        if ($chain === null) {
            return AvailableStarts::none($query->days());
        }

        return $this->startsOver($chain, $query->window, $this->clock->now(), $by);
    }

    /**
     * The first tenant-local date after the query's window holding a start on the start
     * interval, or null when none does up to the smallest horizon across the chain from the
     * tenant's today, or the chain is one nobody can perform. Read forward from the window's
     * end, seven days at a time and no further than the horizon, through the same reads as an
     * answer, thinned to the interval as the public read thins its answer; the starts a customer
     * may not take, inside the lead time or past the horizon, are already gone from each read.
     *
     * The walk also reads no more than Chain::MAX_HORIZON_DAYS days from where it starts, whatever
     * the chain's horizon says (P9F-L15-d), so its cost is bounded by that number even for a
     * horizon no row may hold or a window long past. Counted from the window's end rather than
     * from today, a window starting today keeps a horizon of 365 days whole.
     */
    public function nextAvailableFrom(AvailabilityQuery $query, int $startIntervalMin): ?string
    {
        $chain = $this->chainOf($query);

        if ($chain === null) {
            return null;
        }

        $now = $this->clock->now();
        $zone = $this->tenant->zone();
        $end = CalendarDay::plusDays($now->setTimezone($zone)->format('Y-m-d'), $chain->horizonDays() + 1);
        $cap = CalendarDay::plusDays($query->to, Chain::MAX_HORIZON_DAYS);

        if (CalendarDay::compare($cap, $end) < 0) {
            $end = $cap;
        }

        $from = $query->to;

        while (CalendarDay::compare($from, $end) < 0) {
            $to = CalendarDay::plusDays($from, min(self::WALK_DAYS, CalendarDay::daysBetween($from, $end)));
            $day = $this->startsOver($chain, new DayWindow($from, $to), $now)->every($startIntervalMin, $zone)->firstDayWithAStart();

            if ($day !== null) {
                return $day;
            }

            $from = $to;
        }

        return null;
    }

    /**
     * The chain's starts on the days of a window, from masks read over that window widened, as
     * whoever is asking may take them at now, a customer unless the caller says the business.
     */
    private function startsOver(Chain $chain, DayWindow $days, DateTimeImmutable $now, Actor $by = Actor::Customer): AvailableStarts
    {
        $window = new ChainWindow($chain, $days);

        return $window->starts($this->availability->masks($chain->staffIds(), $window->firstDay, $window->dayCount), $now, $this->tenant->zone(), $by);
    }

    /**
     * The chain the choices describe, or null when some segment's service has nobody to
     * perform it, which no chain can represent and no customer can book.
     */
    private function chainOf(AvailabilityQuery $query): ?Chain
    {
        $services = $this->services->bookable($query->serviceIds());
        $segments = [];

        foreach ($query->items as $index => $item) {
            $service = $services[$item->serviceId] ?? throw new InvalidArgumentException(sprintf('Item %d names a service that is not available.', $index));

            if ($item->staffId !== null && ! $service->performedBy($item->staffId)) {
                throw new InvalidArgumentException(sprintf('Item %d names a staff member who does not perform its service.', $index));
            }

            if ($service->staffIds === []) {
                return null;
            }

            $segments[] = $service->segment($item->staffId);
        }

        return new Chain($segments, $query->sameStaff);
    }
}
