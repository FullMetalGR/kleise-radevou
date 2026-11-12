<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\SegmentShape;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Availability\WindowMasks;
use App\Domain\Chain\Chain;
use App\Domain\Chain\ChainResolver;
use App\Domain\Chain\ChainSegment;
use Random\Engine\Mt19937;
use Random\Randomizer;

function resolverWindow(string $firstDay = '2026-03-28', int $dayCount = 1, int $minutes = 5): WindowGeometry
{
    return WindowGeometry::spanning($firstDay, $dayCount, new DateTimeZone('Europe/Athens'), Granularity::minutes($minutes));
}

function resolverInstant(string $text): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone('Europe/Athens'));
}

function resolverBlock(string $start, string $end): BlockRange
{
    return new BlockRange(resolverInstant($start), resolverInstant($end));
}

/**
 * One staff member's masks over a window: on duty every day inside each of the windows given,
 * with the blocks wherever they fall.
 *
 * @param  list<array{string, string}>  $hours  open and close, wall-clock
 * @param  list<BlockRange>  $blocks
 */
function resolverMasks(WindowGeometry $window, int $staffId, array $hours = [['09:00', '18:00']], array $blocks = []): WindowMasks
{
    $rules = [];

    foreach (range(1, 7) as $weekday) {
        foreach ($hours as [$open, $close]) {
            $rules[] = new AvailabilityRule($weekday, WallClockTime::of($open), WallClockTime::of($close));
        }
    }

    $days = [];

    foreach ($window->days as $day) {
        $days[] = (new MaskBuilder)->build($day, $staffId, $rules, [], $blocks);
    }

    return WindowMasks::of($window, $days);
}

/**
 * @param  list<int>  $eligible
 */
function resolverSegment(int $durationMin, array $eligible, int $gapMin = 0, ?int $pick = null, int $bufferBeforeMin = 0, int $bufferAfterMin = 0, int $leadTimeMin = 0, int $horizonDays = 60): ChainSegment
{
    return new ChainSegment(new SegmentShape($durationMin, $bufferBeforeMin, $bufferAfterMin), $gapMin, $eligible, $pick, $leadTimeMin, $horizonDays);
}

/**
 * The feasible starts as wall-clock readings in the tenant zone, through the window's own
 * conversion.
 *
 * @return list<string>
 */
function resolverOffered(ChainResolver $resolver, string $format = 'H:i'): array
{
    return array_map(
        fn (int $index): string => $resolver->window->instantAt($index)->format($format),
        $resolver->feasibleStarts()->setIndices(),
    );
}

/**
 * Every reading from one to another inclusive, five minutes apart, on one day.
 *
 * @return list<string>
 */
function resolverEvery(string $from, string $to): array
{
    $readings = [];

    for ($at = resolverInstant('2026-03-28 '.$from); $at <= resolverInstant('2026-03-28 '.$to); $at = $at->modify('+5 minutes')) {
        $readings[] = $at->format('H:i');
    }

    return $readings;
}

/**
 * The cut-and-colour chain: a 30-minute cut on staff 1 or 2, a 30-minute processing gap,
 * then a 60-minute colour on staff 3. Staff 1 works 09:00 to 12:00, staff 2 11:00 to 14:00,
 * staff 3 10:00 to 18:00 with a booking from 12:00 to 13:00.
 *
 * @param  list<BlockRange>  $cutterBlocks  bookings on staff 1
 * @param  list<BlockRange>  $colouristBlocks  bookings on staff 3, besides the one at noon
 */
function resolverCutAndColour(?int $cutPick = null, bool $sameStaff = false, array $cutterBlocks = [], array $colouristBlocks = []): ChainResolver
{
    $window = resolverWindow();
    $chain = new Chain([resolverSegment(30, [1, 2], 30, $cutPick), resolverSegment(60, [3])], $sameStaff);

    return new ChainResolver($chain, [
        1 => resolverMasks($window, 1, [['09:00', '12:00']], $cutterBlocks),
        2 => resolverMasks($window, 2, [['11:00', '14:00']]),
        3 => resolverMasks($window, 3, [['10:00', '18:00']], [resolverBlock('2026-03-28 12:00', '2026-03-28 13:00'), ...$colouristBlocks]),
    ]);
}

/**
 * The rule for one segment on one member's masks at one start, walked slot by slot: the
 * not-booked slots from the start minus the buffer before, for the buffer before plus the
 * duration plus the buffer after, all free and inside the window, and the open slots from the
 * start for the duration all free.
 */
function resolverFitsAt(WindowMasks $masks, SegmentShape $shape, int $start): bool
{
    $minutes = $masks->window->granularity->minutes;
    $before = intdiv($shape->bufferBeforeMin + $minutes - 1, $minutes);
    $duration = intdiv($shape->durationMin + $minutes - 1, $minutes);
    $after = intdiv($shape->bufferAfterMin + $minutes - 1, $minutes);
    $from = $start - $before;
    $to = $start + $duration + $after;

    if ($from < 0 || $to > $masks->window->width) {
        return false;
    }

    for ($index = $from; $index < $to; $index++) {
        if (! $masks->notBooked->isSet($index)) {
            return false;
        }
    }

    for ($index = $start; $index < $start + $duration; $index++) {
        if (! $masks->open->isSet($index)) {
            return false;
        }
    }

    return true;
}

/**
 * The members able to take each segment of a chain starting at one slot, by checking each
 * candidate directly at the start plus the segment's offset, the offsets summed in minutes
 * rounded up to slots here rather than asked of the chain.
 *
 * @param  array<int, WindowMasks>  $masks
 * @return list<list<int>>
 */
function resolverAbleAt(Chain $chain, array $masks, int $start): array
{
    $window = $masks[array_key_first($masks) ?? 0]->window;
    $minutes = $window->granularity->minutes;
    $offset = 0;
    $able = [];

    foreach ($chain->segments as $segment) {
        $members = [];
        $at = $start + $offset;

        foreach ($segment->candidates() as $staffId) {
            if ($at < $window->width && resolverFitsAt($masks[$staffId], $segment->shape, $at)) {
                $members[] = $staffId;
            }
        }

        $able[] = $members;
        $offset += intdiv($segment->shape->durationMin + $minutes - 1, $minutes) + intdiv($segment->processingGapMin + $minutes - 1, $minutes);
    }

    return $able;
}

/**
 * The members able to take every segment.
 *
 * @param  list<list<int>>  $able
 * @return list<int>
 */
function resolverCommonAble(array $able): array
{
    $common = $able[0];

    foreach (array_slice($able, 1) as $members) {
        $common = array_values(array_intersect($common, $members));
    }

    return $common;
}

/**
 * The feasible starts of a chain by checking every candidate start directly: plain when every
 * segment has somebody, same-staff when one member has every segment.
 *
 * @param  array<int, WindowMasks>  $masks
 * @return list<int>
 */
function resolverStartsByWalking(Chain $chain, array $masks): array
{
    $width = $masks[array_key_first($masks) ?? 0]->window->width;
    $starts = [];

    for ($start = 0; $start < $width; $start++) {
        $able = resolverAbleAt($chain, $masks, $start);

        $feasible = $chain->sameStaff
            ? resolverCommonAble($able) !== []
            : ! in_array([], $able, true);

        if ($feasible) {
            $starts[] = $start;
        }
    }

    return $starts;
}

/**
 * The least loaded of some members, the lowest id among equals, by sorting rather than by
 * walking.
 *
 * @param  list<int>  $members
 * @param  array<int, int>  $loads
 */
function resolverLeastLoaded(array $members, array $loads): ?int
{
    if ($members === []) {
        return null;
    }

    usort($members, fn (int $left, int $right): int => [$loads[$left] ?? 0, $left] <=> [$loads[$right] ?? 0, $right]);

    return $members[0];
}

/**
 * The assignment for a start by checking directly: one member per segment, or one member for
 * all of them under the preference, or nothing.
 *
 * @param  array<int, WindowMasks>  $masks
 * @param  array<int, int>  $loads
 * @return list<int>|null
 */
function resolverAssignByWalking(Chain $chain, array $masks, int $start, array $loads): ?array
{
    $able = resolverAbleAt($chain, $masks, $start);

    if ($chain->sameStaff) {
        $choice = resolverLeastLoaded(resolverCommonAble($able), $loads);

        return $choice === null ? null : array_fill(0, count($able), $choice);
    }

    $chosen = [];

    foreach ($able as $members) {
        $choice = resolverLeastLoaded($members, $loads);

        if ($choice === null) {
            return null;
        }

        $chosen[] = $choice;
    }

    return $chosen;
}

it('offers a cut on staff 1 or 2 followed by a colour on staff 3 an hour later exactly where somebody can cut and the colourist can colour', function () {
    $offered = resolverOffered(resolverCutAndColour());

    // The colour, at the start plus an hour, fits staff 3 from 10:00 to 11:00 and from 13:00
    // to 17:00, so the chain starts from 09:00 to 10:00 and from 12:00 to 16:00; the cut fits
    // staff 1 from 09:00 to 11:30 and staff 2 from 11:00 to 13:30.
    expect($offered)->toBe([...resolverEvery('09:00', '10:00'), ...resolverEvery('12:00', '13:30')])
        ->and($offered)->toHaveCount(32);
});

it('lets the processing gap block nothing on anybody: a booking on the cutter or on the colourist during the gap changes nothing', function () {
    // A chain starting 09:00 cuts from 09:00 to 09:30 and colours from 10:00 to 11:00; the
    // gap is 09:30 to 10:00 on everybody's clock.
    $cutterBusy = resolverOffered(resolverCutAndColour(cutterBlocks: [resolverBlock('2026-03-28 09:30', '2026-03-28 10:00')]));
    $colouristBusy = resolverOffered(resolverCutAndColour(colouristBlocks: [resolverBlock('2026-03-28 09:30', '2026-03-28 10:00')]));
    $bothBusy = resolverOffered(resolverCutAndColour(
        cutterBlocks: [resolverBlock('2026-03-28 09:30', '2026-03-28 10:00')],
        colouristBlocks: [resolverBlock('2026-03-28 09:30', '2026-03-28 10:00')],
    ));

    expect($cutterBusy)->toContain('09:00')
        ->and($cutterBusy)->not->toContain('09:05')
        ->and($cutterBusy)->toContain('10:00')
        ->and($colouristBusy)->toBe(resolverOffered(resolverCutAndColour()))
        ->and($bothBusy)->toBe($cutterBusy);
});

it('narrows a segment to the one staff member the customer picked', function () {
    expect(resolverOffered(resolverCutAndColour(1)))->toBe(resolverEvery('09:00', '10:00'))
        ->and(resolverOffered(resolverCutAndColour(2)))->toBe(resolverEvery('12:00', '13:30'))
        ->and(resolverOffered(resolverCutAndColour()))->toBe([...resolverEvery('09:00', '10:00'), ...resolverEvery('12:00', '13:30')]);
});

it('offers under the same-staff preference only the starts one member can carry through every segment, refusing a start the plain form offers with two members', function () {
    // Staff 1 works 09:00 to 11:00 and staff 2 10:30 to 14:00; both may cut and both may
    // colour. At 09:30 staff 1 can cut and staff 2 can colour from 10:30, but neither can do
    // both: staff 1 closes before the colour ends and staff 2 opens after the cut starts.
    $window = resolverWindow();
    $masks = [
        1 => resolverMasks($window, 1, [['09:00', '11:00']]),
        2 => resolverMasks($window, 2, [['10:30', '14:00']]),
    ];
    $segments = [resolverSegment(30, [1, 2], 30), resolverSegment(60, [1, 2])];

    $plain = resolverOffered(new ChainResolver(new Chain($segments), $masks));
    $sameStaff = resolverOffered(new ChainResolver(new Chain($segments, true), $masks));

    expect($plain)->toBe(['09:00', ...resolverEvery('09:30', '12:00')])
        ->and($sameStaff)->toBe(['09:00', ...resolverEvery('10:30', '12:00')])
        ->and($plain)->toContain('09:30')
        ->and($sameStaff)->not->toContain('09:30')
        ->and(array_diff($sameStaff, $plain))->toBe([]);
});

it('offers nothing under the same-staff preference when no member is eligible for every segment, and everything the plain form offers when one member is', function () {
    $window = resolverWindow();
    $masks = [1 => resolverMasks($window, 1), 2 => resolverMasks($window, 2), 3 => resolverMasks($window, 3)];

    $disjoint = new ChainResolver(new Chain([resolverSegment(30, [1, 2], 30), resolverSegment(60, [3])], true), $masks);
    $oneMember = new ChainResolver(new Chain([resolverSegment(30, [1], 30), resolverSegment(60, [1, 2])], true), $masks);

    expect($disjoint->feasibleStarts()->setIndices())->toBe([])
        ->and(resolverOffered($oneMember))->toBe(resolverEvery('09:00', '16:00'));
});

it('agrees with a resolver that checks every candidate start directly on a few hundred random chains of one to three segments over one to three staff members', function () {
    $random = new Randomizer(new Mt19937(20260922));
    $windows = [resolverWindow('2026-03-29', 1, 60), resolverWindow('2026-10-25', 1, 30), resolverWindow('2026-03-28', 2, 15), resolverWindow('2026-05-01', 1, 15)];
    $trials = 0;
    $feasibleStarts = 0;
    $assignments = 0;

    for ($trial = 0; $trial < 300; $trial++) {
        $window = $windows[$random->getInt(0, count($windows) - 1)];
        $staffIds = range(1, $random->getInt(1, 3));
        $masks = [];

        foreach ($staffIds as $staffId) {
            $open = FreeBusyMask::allClear($window->width);
            $notBooked = FreeBusyMask::allSet($window->width);

            for ($range = $random->getInt(1, 6); $range > 0; $range--) {
                $from = $random->getInt(0, $window->width - 1);
                $open = $open->set($from, $random->getInt($from, $window->width));
            }

            for ($range = $random->getInt(0, 4); $range > 0; $range--) {
                $from = $random->getInt(0, $window->width - 1);
                $notBooked = $notBooked->clear($from, $random->getInt($from, $window->width));
            }

            $masks[$staffId] = new WindowMasks($window, $open, $notBooked);
        }

        $segments = [];

        for ($count = $random->getInt(1, 3); $count > 0; $count--) {
            $eligible = array_values(array_filter($staffIds, fn (): bool => $random->getInt(0, 1) === 1));

            if ($eligible === []) {
                $eligible = [$staffIds[$random->getInt(0, count($staffIds) - 1)]];
            }

            $pick = $random->getInt(0, 3) === 0 ? $eligible[$random->getInt(0, count($eligible) - 1)] : null;
            $segments[] = resolverSegment($random->getInt(1, 180), $eligible, $random->getInt(0, 120), $pick, $random->getInt(0, 60), $random->getInt(0, 60));
        }

        $chain = new Chain($segments, $random->getInt(0, 1) === 1);
        $resolver = new ChainResolver($chain, $masks);
        $label = sprintf('trial %d: %d segments over %d members on a window of width %d, same staff %s', $trial, count($segments), count($staffIds), $window->width, $chain->sameStaff ? 'yes' : 'no');

        $walked = resolverStartsByWalking($chain, $masks);

        expect($resolver->feasibleStarts()->setIndices())->toBe($walked, $label);

        $loads = [];

        foreach ($staffIds as $staffId) {
            $loads[$staffId] = $random->getInt(0, 2);
        }

        $probes = $walked === [] ? [] : [$walked[$random->getInt(0, count($walked) - 1)]];
        $probes[] = $random->getInt(0, $window->width - 1);

        foreach ($probes as $start) {
            expect($resolver->eligibleAt($start))->toBe(resolverAbleAt($chain, $masks, $start), $label.sprintf(', eligible at %d', $start))
                ->and($resolver->assign($start, $loads))->toBe(resolverAssignByWalking($chain, $masks, $start, $loads), $label.sprintf(', assigned at %d', $start));
            $assignments++;
        }

        $feasibleStarts += count($walked);
        $trials++;
    }

    expect($trials)->toBe(300)
        ->and($feasibleStarts)->toBeGreaterThan(1000)
        ->and($assignments)->toBeGreaterThan(400);
});

it('resolves a chain crossing midnight over concatenated days: a cut at 23:30 with the colour at 00:30 the next day', function () {
    $twoDays = resolverWindow('2026-05-01', 2);
    $segments = [resolverSegment(30, [1], 30), resolverSegment(60, [3])];
    $cutter = [['20:00', '24:00'], ['00:00', '02:00']];
    $allDay = [['00:00', '24:00']];

    $free = new ChainResolver(new Chain($segments), [1 => resolverMasks($twoDays, 1, $cutter), 3 => resolverMasks($twoDays, 3, $allDay)]);
    $colouristBusy = new ChainResolver(new Chain($segments), [1 => resolverMasks($twoDays, 1, $cutter), 3 => resolverMasks($twoDays, 3, $allDay, [resolverBlock('2026-05-02 00:30', '2026-05-02 01:00')])]);
    $oneDay = new ChainResolver(new Chain($segments), [1 => resolverMasks(resolverWindow('2026-05-01', 1), 1, $cutter), 3 => resolverMasks(resolverWindow('2026-05-01', 1), 3, $allDay)]);

    expect(resolverOffered($free, 'Y-m-d H:i'))->toContain('2026-05-01 23:30')
        ->and(resolverOffered($free, 'Y-m-d H:i'))->toContain('2026-05-02 00:00')
        ->and(resolverOffered($colouristBusy, 'Y-m-d H:i'))->not->toContain('2026-05-01 23:30')
        ->and(resolverOffered($colouristBusy, 'Y-m-d H:i'))->toContain('2026-05-01 22:30')
        ->and(resolverOffered($colouristBusy, 'Y-m-d H:i'))->toContain('2026-05-02 00:00')
        ->and(resolverOffered($oneDay, 'Y-m-d H:i'))->not->toContain('2026-05-01 23:30')
        ->and(resolverOffered($oneDay, 'Y-m-d H:i'))->not->toContain('2026-05-01 22:30')
        ->and(resolverOffered($oneDay, 'Y-m-d H:i'))->toContain('2026-05-01 22:00');
});

it('counts the offset in slots across the spring change: from 02:30+02:00 on 2026-03-29 the colour starts at 04:30+03:00, an hour of elapsed time later', function () {
    $window = resolverWindow('2026-03-29');
    $segments = [resolverSegment(30, [1], 30), resolverSegment(60, [3])];
    $allDay = [['00:00', '24:00']];

    $resolver = new ChainResolver(new Chain($segments), [
        1 => resolverMasks($window, 1, $allDay),
        3 => resolverMasks($window, 3, $allDay, [resolverBlock('2026-03-29 04:30', '2026-03-29 05:30')]),
    ]);
    $offered = resolverOffered($resolver, 'H:i P');

    // Counted on the wall clock, 02:30 plus an hour would be 03:30, a time the day does not
    // have, and the booking at 04:30 would seem to leave the colour alone. The 23 starts
    // whose colour would touch the booking are refused, as are the 23 whose colour would run
    // past the window.
    expect($resolver->chain->offsets($window->granularity))->toBe([0, 12])
        ->and($window->instantAt(30)->format('H:i P'))->toBe('02:30 +02:00')
        ->and($window->instantAt(42)->format('H:i P'))->toBe('04:30 +03:00')
        ->and($offered)->not->toContain('02:30 +02:00')
        ->and($offered)->not->toContain('02:00 +02:00')
        ->and($offered)->not->toContain('01:35 +02:00')
        ->and($offered)->toContain('01:30 +02:00')
        ->and($offered)->not->toContain('04:25 +03:00')
        ->and($offered)->toContain('04:30 +03:00')
        ->and($offered)->toHaveCount(276 - 23 - 23);
});

it('counts the offset in slots across the autumn change: from 03:30+03:00 on 2026-10-25 the colour starts at the second 03:30, +02:00', function () {
    $window = resolverWindow('2026-10-25');
    $segments = [resolverSegment(30, [1], 30), resolverSegment(60, [3])];
    $allDay = [['00:00', '24:00']];

    $resolver = new ChainResolver(new Chain($segments), [
        1 => resolverMasks($window, 1, $allDay),
        3 => resolverMasks($window, 3, $allDay, [resolverBlock('2026-10-25T03:30:00+02:00', '2026-10-25T04:30:00+02:00')]),
    ]);
    $offered = resolverOffered($resolver, 'H:i P');

    expect($window->instantAt(42)->format('H:i P'))->toBe('03:30 +03:00')
        ->and($window->instantAt(54)->format('H:i P'))->toBe('03:30 +02:00')
        ->and($offered)->not->toContain('03:30 +03:00')
        ->and($offered)->not->toContain('03:00 +03:00')
        ->and($offered)->not->toContain('02:35 +03:00')
        ->and($offered)->toContain('02:30 +03:00')
        ->and($offered)->not->toContain('03:25 +02:00')
        ->and($offered)->toContain('03:30 +02:00')
        ->and($offered)->toHaveCount(300 - 23 - 23);
});

it('answers per segment the eligible members whose own start mask is set at the start plus the offset, ascending', function () {
    $window = resolverWindow();
    $chain = new Chain([resolverSegment(30, [2, 1], 30), resolverSegment(60, [3, 2])]);
    $resolver = new ChainResolver($chain, [
        1 => resolverMasks($window, 1, [['09:00', '12:00']]),
        2 => resolverMasks($window, 2, [['09:00', '18:00']], [resolverBlock('2026-03-28 10:00', '2026-03-28 10:30')]),
        3 => resolverMasks($window, 3, [['10:00', '18:00']]),
    ]);
    $nine = $window->dayFor('2026-03-28')->indexAtOrAfter(resolverInstant('2026-03-28 09:00'));

    // At 09:00 the cut fits staff 1 and 2, and the colour at 10:00 fits staff 3 but not staff
    // 2, booked at 10:00. At 09:45 the cut on staff 2 would run into that booking, and the
    // colour at 10:45 fits both 2 and 3. At 13:00 staff 1 has gone home and staff 2 is alone.
    expect($resolver->eligibleAt($nine))->toBe([[1, 2], [3]])
        ->and($resolver->eligibleAt($nine + 9))->toBe([[1], [2, 3]])
        ->and($resolver->eligibleAt($nine + 48))->toBe([[2], [2, 3]])
        ->and($resolver->eligibleAt($nine + 96))->toBe([[2], []])
        ->and($resolver->eligibleAt($window->width - 1))->toBe([[], []]);
});

it('assigns the least loaded eligible member per segment, the lowest id breaking a tie, a missing load counting as none, and the same answer on every run', function () {
    $window = resolverWindow();
    $chain = new Chain([resolverSegment(30, [1, 2], 30), resolverSegment(60, [2, 3])]);
    $resolver = new ChainResolver($chain, [1 => resolverMasks($window, 1), 2 => resolverMasks($window, 2), 3 => resolverMasks($window, 3)]);
    $nine = 108;

    expect($resolver->eligibleAt($nine))->toBe([[1, 2], [2, 3]])
        ->and($resolver->assign($nine, []))->toBe([1, 2])
        ->and($resolver->assign($nine, [1 => 3, 2 => 1, 3 => 1]))->toBe([2, 2])
        ->and($resolver->assign($nine, [1 => 3, 2 => 2, 3 => 1]))->toBe([2, 3])
        ->and($resolver->assign($nine, [2 => 5]))->toBe([1, 3])
        ->and($resolver->assign($nine, [1 => 5, 3 => 5]))->toBe([2, 2]);

    $answers = [];

    for ($run = 0; $run < 5; $run++) {
        $answers[] = (new ChainResolver($chain, [3 => resolverMasks($window, 3), 1 => resolverMasks($window, 1), 2 => resolverMasks($window, 2)]))->assign($nine, [1 => 3, 2 => 2, 3 => 1]);
    }

    expect(array_unique($answers, SORT_REGULAR))->toBe([[2, 3]]);
});

it('assigns nobody when some segment has no eligible member at the start', function () {
    $window = resolverWindow();
    $chain = new Chain([resolverSegment(30, [1], 30), resolverSegment(60, [3])]);
    $resolver = new ChainResolver($chain, [1 => resolverMasks($window, 1, [['09:00', '12:00']]), 3 => resolverMasks($window, 3, [['10:00', '18:00']])]);

    expect($resolver->assign(108, []))->toBe([1, 3])
        ->and($resolver->eligibleAt(144))->toBe([[], [3]])
        ->and($resolver->assign(144, []))->toBeNull();
});

it('assigns one member for every segment under the same-staff preference, or nobody when no one member can carry the whole chain', function () {
    $window = resolverWindow();
    $masks = [
        1 => resolverMasks($window, 1),
        2 => resolverMasks($window, 2, [['09:00', '18:00']], [resolverBlock('2026-03-28 10:00', '2026-03-28 10:30')]),
        3 => resolverMasks($window, 3),
    ];
    $segments = [resolverSegment(30, [1, 2], 30), resolverSegment(60, [2, 3])];
    $sameStaff = new ChainResolver(new Chain($segments, true), $masks);
    $plain = new ChainResolver(new Chain($segments), $masks);
    $nine = 108;

    // At 09:00 staff 2 is the one member eligible for both, and the colour at 10:00 runs into
    // their booking; the plain form still finds a cutter and a colourist.
    expect($sameStaff->eligibleAt($nine))->toBe([[1, 2], [3]])
        ->and($sameStaff->assign($nine, []))->toBeNull()
        ->and($plain->assign($nine, []))->toBe([1, 3])
        ->and($sameStaff->eligibleAt($nine + 9))->toBe([[1], [2, 3]])
        ->and($sameStaff->assign($nine + 9, []))->toBeNull()
        ->and($sameStaff->eligibleAt($nine + 18))->toBe([[1, 2], [2, 3]])
        ->and($sameStaff->assign($nine + 18, []))->toBe([2, 2])
        ->and($sameStaff->assign($nine + 18, [2 => 9]))->toBe([2, 2])
        ->and($plain->assign($nine + 18, [2 => 9]))->toBe([1, 3]);
});

it('feeds the slot generator the largest lead time and the smallest horizon among the segments', function () {
    $window = resolverWindow('2026-05-01', 4);
    $chain = new Chain([
        resolverSegment(30, [1], 30, leadTimeMin: 30, horizonDays: 60),
        resolverSegment(60, [1], leadTimeMin: 120, horizonDays: 1),
        resolverSegment(15, [1], leadTimeMin: 0, horizonDays: 30),
    ]);
    $resolver = new ChainResolver($chain, [1 => resolverMasks($window, 1, [['00:00', '24:00']])]);
    $generator = $resolver->slotGenerator(resolverInstant('2026-05-01 10:00'), '2026-05-01');

    expect($chain->leadTimeMin())->toBe(120)
        ->and($chain->horizonDays())->toBe(1)
        ->and($generator->startsOn('2026-05-01')[0]->format('Y-m-d H:i'))->toBe('2026-05-01 12:00')
        ->and($generator->datesWithStarts())->toBe(['2026-05-01', '2026-05-02'])
        ->and($generator->startsOn('2026-05-03'))->toBe([])
        ->and($generator->startsOn('2026-05-02'))->toHaveCount(288);
});

it('resolves a three-segment chain over ten staff members and a sixty-day window at five minutes in under 100 milliseconds', function () {
    $window = resolverWindow('2026-03-01', 60);
    $random = new Randomizer(new Mt19937(5));
    $masks = [];

    foreach (range(1, 10) as $staffId) {
        $open = FreeBusyMask::allClear($window->width);
        $notBooked = FreeBusyMask::allSet($window->width);

        foreach ($window->days as $day) {
            $offset = $window->offsetOf($day->day);
            $open = $open->set($offset + 108, $offset + 216);
        }

        for ($booking = 0; $booking < 40; $booking++) {
            $from = $random->getInt(0, $window->width - 13);
            $notBooked = $notBooked->clear($from, $from + $random->getInt(3, 12));
        }

        $masks[$staffId] = new WindowMasks($window, $open, $notBooked);
    }

    $staffIds = range(1, 10);
    $chains = [
        new Chain([resolverSegment(30, $staffIds, 30, null, 5, 10), resolverSegment(60, $staffIds, 15), resolverSegment(45, $staffIds, 0, null, 0, 15)]),
        new Chain([resolverSegment(30, $staffIds, 30, null, 5, 10), resolverSegment(60, $staffIds, 15), resolverSegment(45, $staffIds, 0, null, 0, 15)], true),
    ];

    // The budget is a design gate against a loop over candidate starts, not a benchmark: a
    // loop over the 17280 starts of this window, checking three segments on ten members each,
    // is a hundred times over it. The fastest of several runs is taken so that a loaded
    // runner does not fail the gate on noise.
    $fastest = PHP_FLOAT_MAX;
    $offered = [];

    for ($run = 0; $run < 5; $run++) {
        $began = hrtime(true);

        foreach ($chains as $chain) {
            $offered[] = (new ChainResolver($chain, $masks))->feasibleStarts();
        }

        $fastest = min($fastest, (hrtime(true) - $began) / 1_000_000);
    }

    expect($window->width)->toBe(60 * 288 - 12)
        ->and($offered[0]->setIndices())->not->toBe([])
        ->and($offered[1]->setIndices())->not->toBe([])
        ->and($fastest)->toBeLessThan(100.0, sprintf('%.1f milliseconds for both forms', $fastest));
});

it('ignores masks for a member the chain cannot touch, and refuses a missing member and masks over another window', function () {
    $window = resolverWindow();
    $chain = new Chain([resolverSegment(30, [1, 2], 30, 1), resolverSegment(60, [3])]);

    $spare = new ChainResolver($chain, [1 => resolverMasks($window, 1), 3 => resolverMasks($window, 3), 9 => resolverMasks(resolverWindow('2026-04-01', 2), 9)]);
    $rebuilt = new ChainResolver($chain, [1 => resolverMasks(resolverWindow(), 1), 3 => resolverMasks(resolverWindow(), 3)]);

    expect($spare->window)->toBe($window)
        ->and($spare->feasibleStarts()->equals($rebuilt->feasibleStarts()))->toBeTrue();

    expect(fn () => new ChainResolver($chain, [1 => resolverMasks($window, 1)]))
        ->toThrow(InvalidArgumentException::class, 'No masks were given for staff member 3.');
    expect(fn () => new ChainResolver($chain, [1 => resolverMasks($window, 1), 3 => resolverMasks(resolverWindow('2026-03-28', 2), 3)]))
        ->toThrow(InvalidArgumentException::class, 'Every staff member\'s masks lie over one window: staff member 1 has 2026-03-28 to 2026-03-28 in Europe/Athens at 5 minutes, staff member 3 has 2026-03-28 to 2026-03-29 in Europe/Athens at 5 minutes.');
    expect(fn () => new ChainResolver($chain, [1 => resolverMasks($window, 1), 3 => resolverMasks(resolverWindow('2026-03-28', 1, 15), 3)]))
        ->toThrow(InvalidArgumentException::class, 'Every staff member\'s masks lie over one window: staff member 1 has 2026-03-28 to 2026-03-28 in Europe/Athens at 5 minutes, staff member 3 has 2026-03-28 to 2026-03-28 in Europe/Athens at 15 minutes.');
});

it('refuses a start outside the window', function () {
    $window = resolverWindow();
    $resolver = new ChainResolver(new Chain([resolverSegment(30, [1])]), [1 => resolverMasks($window, 1)]);

    expect(fn () => $resolver->eligibleAt(-1))
        ->toThrow(InvalidArgumentException::class, 'A start on a window of width 288 lies between 0 and 287, not -1.');
    expect(fn () => $resolver->assign(288, []))
        ->toThrow(InvalidArgumentException::class, 'A start on a window of width 288 lies between 0 and 287, not 288.');
});
