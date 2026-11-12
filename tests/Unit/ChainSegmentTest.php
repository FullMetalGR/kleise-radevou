<?php

use App\Domain\Availability\SegmentShape;
use App\Domain\Chain\ChainSegment;

/**
 * @param  list<int>  $eligibleStaffIds
 */
function chainSegmentOf(array $eligibleStaffIds = [3], ?int $pickedStaffId = null, int $processingGapMin = 0): ChainSegment
{
    return new ChainSegment(
        shape: new SegmentShape(30),
        processingGapMin: $processingGapMin,
        eligibleStaffIds: $eligibleStaffIds,
        pickedStaffId: $pickedStaffId,
        leadTimeMin: 0,
        horizonDays: 60,
    );
}

it('is a shape, a processing gap, the eligible staff members, the customer\'s pick, a lead time and a horizon', function () {
    $segment = new ChainSegment(
        shape: new SegmentShape(60, 5, 10),
        processingGapMin: 30,
        eligibleStaffIds: [7, 2, 5],
        pickedStaffId: 5,
        leadTimeMin: 120,
        horizonDays: 14,
    );

    expect($segment->shape->durationMin)->toBe(60)
        ->and($segment->processingGapMin)->toBe(30)
        ->and($segment->eligibleStaffIds)->toBe([2, 5, 7])
        ->and($segment->pickedStaffId)->toBe(5)
        ->and($segment->leadTimeMin)->toBe(120)
        ->and($segment->horizonDays)->toBe(14);
});

it('answers every eligible member as its candidates when the customer picked nobody, and the pick alone when they did', function () {
    expect(chainSegmentOf([7, 2, 5])->candidates())->toBe([2, 5, 7])
        ->and(chainSegmentOf([7, 2, 5], 7)->candidates())->toBe([7]);
});

it('refuses a negative processing gap, a negative lead time and a negative horizon', function () {
    expect(fn () => chainSegmentOf([3], null, -5))
        ->toThrow(InvalidArgumentException::class, 'A processing gap is a number of minutes at or above zero, not -5.');
    expect(fn () => new ChainSegment(new SegmentShape(30), 0, [3], null, -1, 60))
        ->toThrow(InvalidArgumentException::class, 'A lead time is a number of minutes at or above zero, not -1.');
    expect(fn () => new ChainSegment(new SegmentShape(30), 0, [3], null, 0, -1))
        ->toThrow(InvalidArgumentException::class, 'A horizon is a number of days at or above zero, not -1.');
});

it('refuses no eligible member, a staff id below one, and a member named twice', function () {
    expect(fn () => chainSegmentOf([]))
        ->toThrow(InvalidArgumentException::class, 'A segment names at least one eligible staff member.');
    expect(fn () => chainSegmentOf([3, 0]))
        ->toThrow(InvalidArgumentException::class, 'A staff id is a positive integer, not 0.');
    expect(fn () => chainSegmentOf([3, 5, 3]))
        ->toThrow(InvalidArgumentException::class, 'A segment names each eligible staff member once, not 3 twice.');
});

it('refuses a pick that is not among the eligible members', function () {
    expect(fn () => chainSegmentOf([3, 5], 4))
        ->toThrow(InvalidArgumentException::class, 'A pick names one of the segment\'s eligible staff members, not 4.');
});
