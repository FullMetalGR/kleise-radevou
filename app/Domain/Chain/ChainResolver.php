<?php

declare(strict_types=1);

namespace App\Domain\Chain;

use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\SlotGenerator;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Availability\WindowMasks;
use App\Domain\Booking\Actor;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The chain decomposition and the staff pick, over one chain and the window masks of every staff
 * member it can touch, all over one window. The masks arrive built; nothing here builds one, and
 * nothing here does zone or bit arithmetic of its own beyond composing the mask operations.
 *
 * Chain segments never overlap in time, so the staff choice for one segment does not constrain
 * another and feasibility decomposes: for each segment, the per-segment start mask of 3.4 on
 * each candidate member, the union over the candidates, shifted toward lower indices by the
 * segment's offset so that it speaks in the chain's own start time; then the intersection over
 * the segments. The same-staff preference moves the union outside: over the members that are a
 * candidate for every segment, the intersection over the segments of that one member's shifted
 * mask, then the union. Every step is a whole-mask operation, linear in the window's width, and
 * no start is ever examined on its own. The shifted masks are built once, at construction, and
 * every question asked afterwards reads them.
 *
 * The masks answer where the chain can start and not who serves it. For a start the customer
 * chose, the members whose own shifted mask is set there are the eligible ones per segment, and
 * the pick among them is the least loaded by a load the caller supplies, the lowest id among
 * equals, so a failure reproduces in a test. Under the preference the pick is one member eligible
 * for every segment or nobody. The pick is advisory: the hold re-validates it under lock, and that
 * is the only answer that counts.
 */
final readonly class ChainResolver
{
    public WindowGeometry $window;

    /**
     * Per segment, by candidate staff id ascending, that member's start mask for the segment
     * shifted toward lower indices by the segment's offset, so that bit s reads whether the
     * segment may start at s plus the offset.
     *
     * @var non-empty-list<array<int, FreeBusyMask>>
     */
    private array $starts;

    /**
     * @param  array<int, WindowMasks>  $masksByStaff  by staff id, each over the one window; a member the chain cannot touch is ignored
     */
    public function __construct(public Chain $chain, array $masksByStaff)
    {
        $staffIds = $chain->staffIds();
        $first = $staffIds[0];
        $window = self::masksFor($masksByStaff, $first)->window;

        foreach ($staffIds as $staffId) {
            $other = self::masksFor($masksByStaff, $staffId)->window;

            if (! self::sameWindow($window, $other)) {
                throw new InvalidArgumentException(sprintf(
                    'Every staff member\'s masks lie over one window: staff member %d has %s, staff member %d has %s.',
                    $first,
                    self::describe($window),
                    $staffId,
                    self::describe($other),
                ));
            }
        }

        $offsets = $chain->offsets($window->granularity);
        $starts = [];

        foreach ($chain->segments as $index => $segment) {
            $shifted = [];

            foreach ($segment->candidates() as $staffId) {
                $shifted[$staffId] = $masksByStaff[$staffId]->segmentStarts($segment->shape)->shiftToLower($offsets[$index]);
            }

            $starts[] = $shifted;
        }

        $this->window = $window;
        $this->starts = $starts;
    }

    /**
     * The mask whose bit s is set when the whole chain can start at window index s: the plain
     * form, or the same-staff form when the chain prefers one member.
     */
    public function feasibleStarts(): FreeBusyMask
    {
        return $this->chain->sameStaff ? $this->sameStaffStarts() : $this->anyStaffStarts();
    }

    /**
     * The feasible starts through the slot generator, with the lead time and the horizon that
     * bind whoever is asking: for a customer the largest lead time and the smallest horizon among
     * the segments, so that the chain is offered no closer and no further ahead than its most
     * demanding service allows; for the business neither, every start from now on. A caller
     * that names nobody is answered as a customer is.
     *
     * @param  string  $today  the tenant's today, YYYY-MM-DD
     */
    public function slotGenerator(DateTimeImmutable $now, string $today, Actor $by = Actor::Customer): SlotGenerator
    {
        return new SlotGenerator($this->window, $this->feasibleStarts(), $now, $today, $this->chain->leadTimeMinFor($by), $this->chain->horizonDaysFor($by));
    }

    /**
     * Per segment, the candidate members whose own start mask is set at the start plus the
     * segment's offset, ascending: the ones the segment could be assigned to for a chain
     * starting at the window index. Empty for a segment nobody can take there.
     *
     * @return non-empty-list<list<int>>
     */
    public function eligibleAt(int $start): array
    {
        $this->assertStart($start);
        $eligible = [];

        foreach ($this->starts as $byStaff) {
            $members = [];

            foreach ($byStaff as $staffId => $mask) {
                if ($mask->isSet($start)) {
                    $members[] = $staffId;
                }
            }

            $eligible[] = $members;
        }

        return $eligible;
    }

    /**
     * One member per segment for a chain starting at the window index: the least loaded of
     * the eligible members by the loads given, the lowest id among equals, a member with no
     * load given counting as unloaded. Under the same-staff preference one member takes
     * every segment, chosen the same way among those eligible for all of them. Null when
     * some segment has nobody, or nobody can carry the whole chain under the preference. The
     * loads are read as given and not adjusted between segments.
     *
     * @param  array<int, int>  $loads  by staff id
     * @return non-empty-list<int>|null by segment
     */
    public function assign(int $start, array $loads): ?array
    {
        $eligible = $this->eligibleAt($start);

        if ($this->chain->sameStaff) {
            $common = $eligible[0];

            foreach (array_slice($eligible, 1) as $members) {
                $common = array_values(array_intersect($common, $members));
            }

            $choice = self::leastLoaded($common, $loads);

            return $choice === null ? null : array_fill(0, count($eligible), $choice);
        }

        $chosen = [];

        foreach ($eligible as $members) {
            $choice = self::leastLoaded($members, $loads);

            if ($choice === null) {
                return null;
            }

            $chosen[] = $choice;
        }

        return $chosen;
    }

    /**
     * The plain form: the intersection over the segments of the union over each segment's
     * candidates of the shifted start masks.
     */
    private function anyStaffStarts(): FreeBusyMask
    {
        $feasible = FreeBusyMask::allSet($this->window->width);

        foreach ($this->starts as $byStaff) {
            $union = FreeBusyMask::allClear($this->window->width);

            foreach ($byStaff as $mask) {
                $union = $union->or($mask);
            }

            $feasible = $feasible->and($union);
        }

        return $feasible;
    }

    /**
     * The same-staff form: the union over the members that are a candidate for every segment
     * of the intersection over the segments of that member's shifted start masks. Empty when
     * no one member is a candidate for every segment.
     */
    private function sameStaffStarts(): FreeBusyMask
    {
        $feasible = FreeBusyMask::allClear($this->window->width);

        foreach ($this->chain->commonStaffIds() as $staffId) {
            $own = FreeBusyMask::allSet($this->window->width);

            foreach ($this->starts as $byStaff) {
                $own = $own->and($byStaff[$staffId]);
            }

            $feasible = $feasible->or($own);
        }

        return $feasible;
    }

    /**
     * The least loaded of some members, ascending by id, the first among equals: a strict
     * comparison is what lets the lowest id win a tie.
     *
     * @param  list<int>  $members  ascending
     * @param  array<int, int>  $loads
     */
    private static function leastLoaded(array $members, array $loads): ?int
    {
        $choice = null;
        $lightest = PHP_INT_MAX;

        foreach ($members as $staffId) {
            $load = $loads[$staffId] ?? 0;

            if ($choice === null || $load < $lightest) {
                $choice = $staffId;
                $lightest = $load;
            }
        }

        return $choice;
    }

    private function assertStart(int $start): void
    {
        if ($start < 0 || $start >= $this->window->width) {
            throw new InvalidArgumentException(sprintf(
                'A start on a window of width %d lies between 0 and %d, not %d.',
                $this->window->width,
                $this->window->width - 1,
                $start,
            ));
        }
    }

    /**
     * @param  array<int, WindowMasks>  $masksByStaff
     */
    private static function masksFor(array $masksByStaff, int $staffId): WindowMasks
    {
        return $masksByStaff[$staffId] ?? throw new InvalidArgumentException(sprintf('No masks were given for staff member %d.', $staffId));
    }

    /**
     * Whether two windows are the same strip of slots: the same days in the same zone at the
     * same granularity, which is what decides what every index means. The same instance is
     * the common case and needs no comparison.
     */
    private static function sameWindow(WindowGeometry $window, WindowGeometry $other): bool
    {
        return $window === $other || (
            $window->width === $other->width
            && count($window->days) === count($other->days)
            && $window->days[0]->day === $other->days[0]->day
            && $window->zone->getName() === $other->zone->getName()
            && $window->granularity->equals($other->granularity)
        );
    }

    private static function describe(WindowGeometry $window): string
    {
        return sprintf(
            '%s to %s in %s at %d minutes',
            $window->days[0]->day,
            $window->days[count($window->days) - 1]->day,
            $window->zone->getName(),
            $window->granularity->minutes,
        );
    }
}
