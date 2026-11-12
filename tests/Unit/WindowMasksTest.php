<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\SegmentShape;
use App\Domain\Availability\StaffDayMasks;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Availability\WindowMasks;
use Random\Engine\Mt19937;
use Random\Randomizer;

function segmentWindow(string $firstDay = '2026-03-28', int $dayCount = 1, int $minutes = 5): WindowGeometry
{
    return WindowGeometry::spanning($firstDay, $dayCount, new DateTimeZone('Europe/Athens'), Granularity::minutes($minutes));
}

function segmentBlock(string $start, string $end): BlockRange
{
    return new BlockRange(new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')), new DateTimeImmutable($end, new DateTimeZone('Europe/Athens')));
}

/**
 * The masks of staff member 3 over a window, on duty every day from open to close, with the
 * blocks given wherever they fall.
 *
 * @param  list<BlockRange>  $blocks
 */
function segmentMasks(WindowGeometry $window, string $open = '09:00', string $close = '18:00', array $blocks = []): WindowMasks
{
    $rules = array_map(fn (int $weekday): AvailabilityRule => new AvailabilityRule($weekday, WallClockTime::of($open), WallClockTime::of($close)), range(1, 7));
    $days = [];

    foreach ($window->days as $day) {
        $days[] = (new MaskBuilder)->build($day, 3, $rules, [], $blocks);
    }

    return WindowMasks::of($window, $days);
}

/**
 * Every offered start as a wall-clock reading in the tenant zone, through the window's own
 * conversion.
 *
 * @return list<string>
 */
function offeredAt(WindowMasks $masks, SegmentShape $shape): array
{
    return array_map(
        fn (int $index): string => $masks->window->instantAt($index)->format('Y-m-d H:i'),
        $masks->segmentStarts($shape)->setIndices(),
    );
}

/**
 * The availability rule walked slot by slot: a start s is offered when the not-booked
 * slots from s minus the buffer before, for the buffer before plus the duration plus the
 * buffer after, are all free and inside the window, and the open slots from s for the
 * duration are all free.
 *
 * @return list<int>
 */
function segmentStartsByWalking(FreeBusyMask $open, FreeBusyMask $notBooked, int $before, int $duration, int $after): array
{
    $starts = [];

    for ($start = 0; $start < $open->width; $start++) {
        $from = $start - $before;
        $to = $start + $duration + $after;

        if ($from < 0 || $to > $open->width) {
            continue;
        }

        $free = true;

        for ($index = $from; $index < $to && $free; $index++) {
            $free = $notBooked->isSet($index);
        }

        for ($index = $start; $index < $start + $duration && $free; $index++) {
            $free = $open->isSet($index);
        }

        if ($free) {
            $starts[] = $start;
        }
    }

    return $starts;
}

it('concatenates the open masks day by day and the not-booked masks likewise, day two\'s slot k at the width of day one plus k', function () {
    $window = segmentWindow('2026-03-28', 2);
    $masks = segmentMasks($window, '09:00', '17:00', [segmentBlock('2026-03-28 23:30', '2026-03-29 00:30')]);

    expect($masks->window)->toBe($window)
        ->and($masks->open->width)->toBe(564)
        ->and($masks->notBooked->width)->toBe(564)
        ->and($masks->open->setIndices())->toBe([...range(108, 203), ...range(384, 479)])
        ->and($masks->notBooked->setIndices())->toBe([...range(0, 281), ...range(294, 563)]);
});

it('refuses the wrong number of staff-days, and masks that are not the width of their day', function () {
    $window = segmentWindow('2026-03-28', 2);
    $saturday = new StaffDayMasks(FreeBusyMask::allSet(288), FreeBusyMask::allSet(288));

    expect(fn () => WindowMasks::of($window, [$saturday]))
        ->toThrow(InvalidArgumentException::class, 'A window of 2 days takes 2 staff-day masks, not 1.');
    expect(fn () => WindowMasks::of($window, [$saturday, $saturday]))
        ->toThrow(InvalidArgumentException::class, 'The masks for 2026-03-29 are 288 wide, not its 276.');
    expect(fn () => new WindowMasks($window, FreeBusyMask::allSet(288), FreeBusyMask::allSet(564)))
        ->toThrow(InvalidArgumentException::class, 'The masks of a window are its width, 564, not 288 and 564.');
    expect(fn () => new WindowMasks($window, FreeBusyMask::allSet(564), FreeBusyMask::allSet(276)))
        ->toThrow(InvalidArgumentException::class, 'The masks of a window are its width, 564, not 564 and 276.');
});

it('offers 17:00 for a 60-minute service with a 15-minute cleanup buffer on hours 09:00 to 18:00, although the buffer runs to 18:15', function () {
    $offered = offeredAt(segmentMasks(segmentWindow()), new SegmentShape(60, 0, 15));

    expect($offered)->toHaveCount(97)
        ->and($offered[0])->toBe('2026-03-28 09:00')
        ->and($offered[96])->toBe('2026-03-28 17:00')
        ->and($offered)->not->toContain('2026-03-28 17:05');
});

it('refuses 09:00 and offers 08:50 against a booking from 10:00 to 11:00 for a 60-minute service with a 10-minute cleanup buffer, and offers 09:00 with the buffer removed', function () {
    $masks = segmentMasks(segmentWindow(), '08:00', '18:00', [segmentBlock('2026-03-28 10:00', '2026-03-28 11:00')]);

    $buffered = offeredAt($masks, new SegmentShape(60, 0, 10));
    $bare = offeredAt($masks, new SegmentShape(60));

    expect($buffered)->toContain('2026-03-28 08:50')
        ->and($buffered)->not->toContain('2026-03-28 08:55')
        ->and($buffered)->not->toContain('2026-03-28 09:00')
        ->and($buffered)->toContain('2026-03-28 11:00')
        ->and($bare)->toContain('2026-03-28 09:00')
        ->and($bare)->not->toContain('2026-03-28 09:05')
        ->and($bare)->toContain('2026-03-28 11:00');
});

it('does not require a 15-minute setup buffer to fall inside opening hours: 09:00 is offered when the open mask starts at 09:00', function () {
    $offered = offeredAt(segmentMasks(segmentWindow()), new SegmentShape(60, 15, 0));

    expect($offered)->toHaveCount(97)
        ->and($offered[0])->toBe('2026-03-28 09:00')
        ->and($offered[96])->toBe('2026-03-28 17:00')
        ->and($offered)->not->toContain('2026-03-28 08:45');
});

it('makes a setup buffer contend with a booking before opening, since buffers occupy the staff member', function () {
    $masks = segmentMasks(segmentWindow(), '09:00', '18:00', [segmentBlock('2026-03-28 08:30', '2026-03-28 09:00')]);

    $buffered = offeredAt($masks, new SegmentShape(60, 15, 0));
    $bare = offeredAt($masks, new SegmentShape(60));

    expect($buffered)->not->toContain('2026-03-28 09:00')
        ->and($buffered)->not->toContain('2026-03-28 09:10')
        ->and($buffered)->toContain('2026-03-28 09:15')
        ->and($bare)->toContain('2026-03-28 09:00');
});

it('rounds the duration and the buffers up to whole slots before the check: a 50-minute service at fifteen needs sixty free minutes', function () {
    $window = segmentWindow('2026-03-28', 1, 15);

    $duration = offeredAt(segmentMasks($window, '09:00', '18:00', [segmentBlock('2026-03-28 10:00', '2026-03-28 10:15')]), new SegmentShape(50));
    $buffer = offeredAt(segmentMasks($window, '09:00', '18:00', [segmentBlock('2026-03-28 10:15', '2026-03-28 10:30')]), new SegmentShape(50, 0, 10));

    expect($duration)->toContain('2026-03-28 09:00')
        ->and($duration)->not->toContain('2026-03-28 09:15')
        ->and($duration)->toContain('2026-03-28 10:15')
        ->and($buffer)->toContain('2026-03-28 09:00')
        ->and($buffer)->not->toContain('2026-03-28 09:15')
        ->and($buffer)->toContain('2026-03-28 10:30');
});

it('offers 23:30 for a 30-minute service with a 30-minute cleanup buffer over two concatenated days when day two is unbooked, and refuses it when day two holds a booking from 00:00 to 00:30', function () {
    $window = segmentWindow('2026-03-28', 2);
    $shape = new SegmentShape(30, 0, 30);

    $unbooked = offeredAt(segmentMasks($window, '00:00', '24:00'), $shape);
    $booked = offeredAt(segmentMasks($window, '00:00', '24:00', [segmentBlock('2026-03-29 00:00', '2026-03-29 00:30')]), $shape);
    $later = offeredAt(segmentMasks($window, '00:00', '24:00', [segmentBlock('2026-03-29 00:30', '2026-03-29 01:00')]), $shape);

    expect($unbooked)->toContain('2026-03-28 23:30')
        ->and($booked)->not->toContain('2026-03-28 23:30')
        ->and($booked)->toContain('2026-03-28 23:00')
        ->and($later)->toContain('2026-03-28 23:30');
});

it('reads the window\'s trailing edge as busy: the same 23:30 is refused when the window ends at that midnight', function () {
    $offered = offeredAt(segmentMasks(segmentWindow('2026-03-28', 1), '00:00', '24:00'), new SegmentShape(30, 0, 30));

    expect($offered)->not->toContain('2026-03-28 23:30')
        ->and($offered)->toContain('2026-03-28 23:00')
        ->and(offeredAt(segmentMasks(segmentWindow('2026-03-28', 1), '00:00', '24:00'), new SegmentShape(30)))->toContain('2026-03-28 23:30');
});

it('does not offer a start whose setup buffer would begin before the window\'s first slot, and offers it once the day before is in the window', function () {
    $shape = new SegmentShape(30, 15, 0);

    $oneDay = offeredAt(segmentMasks(segmentWindow('2026-03-29', 1), '00:00', '24:00'), $shape);
    $twoDays = offeredAt(segmentMasks(segmentWindow('2026-03-28', 2), '00:00', '24:00'), $shape);
    $busyBefore = offeredAt(segmentMasks(segmentWindow('2026-03-28', 2), '00:00', '24:00', [segmentBlock('2026-03-28 23:45', '2026-03-29 00:00')]), $shape);

    expect($oneDay)->not->toContain('2026-03-29 00:00')
        ->and($oneDay)->not->toContain('2026-03-29 00:10')
        ->and($oneDay[0])->toBe('2026-03-29 00:15')
        ->and($twoDays)->toContain('2026-03-29 00:00')
        ->and($busyBefore)->not->toContain('2026-03-29 00:00')
        ->and($busyBefore)->toContain('2026-03-29 00:15');
});

it('offers nothing when the setup buffer is wider than the window, rather than failing', function () {
    $window = segmentWindow('2026-03-28', 1, 60);

    expect(segmentMasks($window, '00:00', '24:00')->segmentStarts(new SegmentShape(30, 24 * 60, 0))->setIndices())->toBe([])
        ->and(segmentMasks($window, '00:00', '24:00')->segmentStarts(new SegmentShape(25 * 60))->setIndices())->toBe([]);
});

it('agrees with the slot-by-slot walk of the rule on a few hundred random windows, masks and shapes', function () {
    $random = new Randomizer(new Mt19937(20260922));
    $windows = [segmentWindow('2026-03-28', 1, 15), segmentWindow('2026-03-28', 2, 60), segmentWindow('2026-10-24', 3, 30), segmentWindow('2026-05-01', 2, 5)];
    $trials = 0;

    for ($trial = 0; $trial < 400; $trial++) {
        $window = $windows[$random->getInt(0, count($windows) - 1)];
        $painted = [];

        foreach (['open', 'notBooked'] as $name) {
            $mask = FreeBusyMask::allClear($window->width);

            for ($range = $random->getInt(1, 8); $range > 0; $range--) {
                $from = $random->getInt(0, $window->width - 1);
                $mask = $mask->set($from, $random->getInt($from, $window->width));
            }

            $painted[$name] = $mask;
        }

        $shape = new SegmentShape($random->getInt(1, 240), $random->getInt(0, 90), $random->getInt(0, 90));
        $minutes = $window->granularity->minutes;
        $before = intdiv($shape->bufferBeforeMin + $minutes - 1, $minutes);
        $duration = intdiv($shape->durationMin + $minutes - 1, $minutes);
        $after = intdiv($shape->bufferAfterMin + $minutes - 1, $minutes);

        $masks = new WindowMasks($window, $painted['open'], $painted['notBooked']);

        expect($masks->segmentStarts($shape)->setIndices())->toBe(
            segmentStartsByWalking($painted['open'], $painted['notBooked'], $before, $duration, $after),
            sprintf('a %d/%d/%d-minute shape on a window of width %d at %d minutes', $shape->bufferBeforeMin, $shape->durationMin, $shape->bufferAfterMin, $window->width, $minutes),
        );
        $trials++;
    }

    expect($trials)->toBe(400);
});
