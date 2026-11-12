<?php

use App\Domain\Availability\Granularity;
use App\Domain\Availability\SegmentShape;
use App\Domain\Chain\Chain;
use App\Domain\Chain\ChainSegment;

/**
 * @param  list<int>  $eligibleStaffIds
 */
function chainLink(int $durationMin, int $processingGapMin = 0, array $eligibleStaffIds = [3], ?int $pickedStaffId = null, int $leadTimeMin = 0, int $horizonDays = 60): ChainSegment
{
    return new ChainSegment(new SegmentShape($durationMin), $processingGapMin, $eligibleStaffIds, $pickedStaffId, $leadTimeMin, $horizonDays);
}

it('is an ordered list of one or more segments, plain unless the same-staff preference is given', function () {
    $cut = chainLink(30);
    $colour = chainLink(60);

    expect((new Chain([$cut, $colour]))->segments)->toBe([$cut, $colour])
        ->and((new Chain([$cut, $colour]))->sameStaff)->toBeFalse()
        ->and((new Chain([$cut], true))->sameStaff)->toBeTrue()
        ->and((new Chain([$cut]))->segments)->toBe([$cut]);
});

it('refuses an empty chain', function () {
    expect(fn () => new Chain([]))->toThrow(InvalidArgumentException::class, 'A chain is at least one segment long.');
});

it('offsets each segment by the durations and processing gaps before it, in slots, each rounded up on its own', function () {
    $chain = new Chain([chainLink(30, 30), chainLink(50, 10), chainLink(45)]);

    expect($chain->offsets(Granularity::minutes(5)))->toBe([0, 12, 24])
        ->and($chain->offsets(Granularity::minutes(15)))->toBe([0, 4, 9])
        ->and($chain->offsets(Granularity::minutes(60)))->toBe([0, 2, 4])
        ->and((new Chain([chainLink(30)]))->offsets(Granularity::minutes(5)))->toBe([0]);
});

it('takes the largest lead time and the smallest horizon among its segments', function () {
    $chain = new Chain([
        chainLink(30, leadTimeMin: 60, horizonDays: 30),
        chainLink(60, leadTimeMin: 1440, horizonDays: 14),
        chainLink(15, leadTimeMin: 0, horizonDays: 60),
    ]);

    expect($chain->leadTimeMin())->toBe(1440)
        ->and($chain->horizonDays())->toBe(14)
        ->and((new Chain([chainLink(30, leadTimeMin: 15, horizonDays: 7)]))->leadTimeMin())->toBe(15)
        ->and((new Chain([chainLink(30, leadTimeMin: 15, horizonDays: 7)]))->horizonDays())->toBe(7);
});

it('names every staff member it can touch, once and ascending, a pick standing in for the eligible members of its segment', function () {
    $chain = new Chain([
        chainLink(30, eligibleStaffIds: [5, 2]),
        chainLink(60, eligibleStaffIds: [9, 2, 4], pickedStaffId: 4),
        chainLink(15, eligibleStaffIds: [5]),
    ]);

    expect($chain->staffIds())->toBe([2, 4, 5]);
});

it('names the staff members that can carry every segment: the candidates common to all', function () {
    expect((new Chain([chainLink(30, eligibleStaffIds: [5, 2, 7]), chainLink(60, eligibleStaffIds: [7, 2, 4])]))->commonStaffIds())->toBe([2, 7])
        ->and((new Chain([chainLink(30, eligibleStaffIds: [5, 2, 7]), chainLink(60, eligibleStaffIds: [7, 2, 4], pickedStaffId: 7)]))->commonStaffIds())->toBe([7])
        ->and((new Chain([chainLink(30, eligibleStaffIds: [5, 2]), chainLink(60, eligibleStaffIds: [7, 4])]))->commonStaffIds())->toBe([])
        ->and((new Chain([chainLink(30, eligibleStaffIds: [5, 2])]))->commonStaffIds())->toBe([2, 5]);
});

it('is at most five segments long, the cap the service list publishes as max_chain_length', function () {
    $five = array_fill(0, Chain::MAX_SEGMENTS, chainLink(30));

    expect(Chain::MAX_SEGMENTS)->toBe(5)
        ->and((new Chain($five))->segments)->toHaveCount(5)
        ->and(fn () => new Chain([...$five, chainLink(30)]))
        ->toThrow(InvalidArgumentException::class, 'A chain is at most 5 segments long, not 6.');
});

it('spans the durations and gaps up to the last segment, the last duration and its cleanup buffer, never the trailing gap or any setup buffer', function () {
    $chain = new Chain([
        new ChainSegment(new SegmentShape(30, 10, 5), 30, [3], null, 0, 60),
        new ChainSegment(new SegmentShape(50, 15, 0), 10, [3], null, 0, 60),
        new ChainSegment(new SegmentShape(45, 20, 25), 40, [3], null, 0, 60),
    ]);

    expect($chain->spanMin())->toBe(30 + 30 + 50 + 10 + 45 + 25)
        ->and((new Chain([new ChainSegment(new SegmentShape(60, 15, 10), 30, [3], null, 0, 60)]))->spanMin())->toBe(70)
        ->and((new Chain([chainLink(30)]))->spanMin())->toBe(30);
});
