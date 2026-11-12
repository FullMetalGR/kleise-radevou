<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\Blackout;
use App\Domain\Availability\BlockRange;
use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\StaffDayMasks;
use App\Domain\Availability\WallClockTime;

function builderDay(string $day = '2026-03-28', int $minutes = 5): StaffDayGeometry
{
    return StaffDayGeometry::of($day, new DateTimeZone('Europe/Athens'), Granularity::minutes($minutes));
}

function builderRule(int $weekday, string $start, string $end): AvailabilityRule
{
    return new AvailabilityRule($weekday, WallClockTime::of($start), WallClockTime::of($end));
}

function builderBlackout(?int $staffId, string $date, ?string $start = null, ?string $end = null): Blackout
{
    return new Blackout($staffId, $date, $start === null ? null : WallClockTime::of($start), $end === null ? null : WallClockTime::of($end));
}

function builderBlock(string $start, string $end): BlockRange
{
    return new BlockRange(new DateTimeImmutable($start, new DateTimeZone('Europe/Athens')), new DateTimeImmutable($end, new DateTimeZone('Europe/Athens')));
}

/**
 * @template T
 *
 * @param  list<T>  $items
 * @return Generator<int, T>
 */
function builderGenerator(array $items): Generator
{
    yield from $items;
}

/**
 * The masks for staff member 3 on the Saturday, on duty 09:00 to 17:00, with whatever
 * blackouts and blocks the test adds.
 *
 * @param  list<Blackout>  $blackouts
 * @param  list<BlockRange>  $blocks
 */
function saturdayMasks(array $blackouts = [], array $blocks = [], string $day = '2026-03-28'): StaffDayMasks
{
    return (new MaskBuilder)->build(builderDay($day), 3, [builderRule(6, '09:00', '17:00'), builderRule(7, '09:00', '17:00')], $blackouts, $blocks);
}

it('returns the open mask and the not-booked mask for one staff-day, both the width of the day', function () {
    $masks = saturdayMasks();

    expect($masks->open->width)->toBe(288)
        ->and($masks->notBooked->width)->toBe(288)
        ->and($masks->open->setIndices())->toBe(range(108, 203))
        ->and($masks->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('keeps the open mask\'s two factors beside it: the member\'s hours with no blackout taken off, and what no blackout covers', function () {
    $masks = saturdayMasks([builderBlackout(3, '2026-03-28', '12:00', '13:00'), builderBlackout(null, '2026-03-28', '20:00', '21:00')]);

    expect($masks->hours->setIndices())->toBe(range(108, 203))
        ->and($masks->unclosed->setIndices())->toBe([...range(0, 143), ...range(156, 239), ...range(252, 287)])
        ->and($masks->open->equals($masks->hours->and($masks->unclosed)))->toBeTrue()
        ->and((new MaskBuilder)->open(builderDay(), 3, [builderRule(6, '09:00', '17:00')], [builderBlackout(3, '2026-03-28', '12:00', '13:00')])->equals($masks->hours->and((new MaskBuilder)->unclosed(builderDay(), 3, [builderBlackout(3, '2026-03-28', '12:00', '13:00')]))))->toBeTrue();
});

it('closes the whole day for a tenant-wide blackout without times', function () {
    $masks = saturdayMasks([builderBlackout(null, '2026-03-28')]);

    expect($masks->open->setIndices())->toBe([])
        ->and($masks->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('closes exactly the window of a personal blackout with times', function () {
    $masks = saturdayMasks([builderBlackout(3, '2026-03-28', '12:00', '13:00')]);

    expect($masks->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)]);
});

it('changes nothing for a blackout naming another staff member, and nothing for one on another date', function () {
    $otherMember = saturdayMasks([builderBlackout(4, '2026-03-28'), builderBlackout(4, '2026-03-28', '12:00', '13:00')]);
    $otherDate = saturdayMasks([builderBlackout(null, '2026-03-27'), builderBlackout(3, '2026-03-29', '12:00', '13:00')]);

    expect($otherMember->open->setIndices())->toBe(range(108, 203))
        ->and($otherDate->open->setIndices())->toBe(range(108, 203));
});

it('closes the window of a tenant-wide blackout with times for this member too, and a whole-day personal one closes the day', function () {
    $tenantWindow = saturdayMasks([builderBlackout(null, '2026-03-28', '09:00', '10:00')]);
    $personalDay = saturdayMasks([builderBlackout(3, '2026-03-28')]);

    expect($tenantWindow->open->setIndices())->toBe(range(120, 203))
        ->and($personalDay->open->setIndices())->toBe([]);
});

it('rounds a blackout window outward, so 12:03 to 12:57 closes 12:00 to 13:00 at five minutes', function () {
    $masks = saturdayMasks([builderBlackout(3, '2026-03-28', '12:03', '12:57')]);

    expect($masks->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)]);
});

it('subtracts several blackouts together', function () {
    $masks = saturdayMasks([
        builderBlackout(3, '2026-03-28', '09:00', '10:00'),
        builderBlackout(null, '2026-03-28', '16:00', '17:00'),
        builderBlackout(3, '2026-03-28', '12:00', '13:00'),
    ]);

    expect($masks->open->setIndices())->toBe([...range(120, 143), ...range(156, 191)]);
});

it('starts the not-booked mask all free and clears each block rounded outward, so 09:03 to 09:17 clears 09:00 to 09:20', function () {
    $masks = saturdayMasks([], [builderBlock('2026-03-28 09:03', '2026-03-28 09:17')]);

    expect($masks->notBooked->setIndices())->toBe([...range(0, 107), ...range(112, 287)])
        ->and($masks->open->setIndices())->toBe(range(108, 203));
});

it('clears a block wherever it falls, inside the open hours or not: the two masks stay separate', function () {
    $masks = saturdayMasks([], [builderBlock('2026-03-28 06:00', '2026-03-28 07:00'), builderBlock('2026-03-28 10:00', '2026-03-28 11:00')]);

    expect($masks->notBooked->setIndices())->toBe([...range(0, 71), ...range(84, 119), ...range(132, 287)])
        ->and($masks->open->setIndices())->toBe(range(108, 203));
});

it('clears only the part of a block inside this day when the block crosses midnight, on either side of it', function () {
    $block = builderBlock('2026-03-28 23:30', '2026-03-29 00:30');

    $saturday = saturdayMasks([], [$block], '2026-03-28');
    $sunday = saturdayMasks([], [$block], '2026-03-29');

    expect($saturday->notBooked->setIndices())->toBe(range(0, 281))
        ->and($sunday->notBooked->setIndices())->toBe(range(6, 275));
});

it('leaves the next day untouched by a block ending exactly at midnight', function () {
    $block = builderBlock('2026-03-28 23:00', '2026-03-29 00:00');

    $saturday = saturdayMasks([], [$block], '2026-03-28');
    $sunday = saturdayMasks([], [$block], '2026-03-29');

    expect($saturday->notBooked->setIndices())->toBe(range(0, 275))
        ->and($sunday->notBooked->equals(FreeBusyMask::allSet(276)))->toBeTrue();
});

it('ignores a block wholly outside the day', function () {
    $masks = saturdayMasks([], [builderBlock('2026-03-27 09:00', '2026-03-27 10:00'), builderBlock('2026-03-30 09:00', '2026-03-30 10:00')]);

    expect($masks->notBooked->equals(FreeBusyMask::allSet(288)))->toBeTrue();
});

it('reads no status and no expiry: the not-booked input is a list of ranges and the builder names neither word', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(MaskBuilder::class, 'build'))->getParameters(),
    );

    expect($parameters)->toBe(['geometry', 'staffId', 'rules', 'blackouts', 'blocks']);

    $directory = dirname(__DIR__, 2).'/app/Domain/Availability';
    $offences = [];

    foreach (['MaskBuilder', 'BlockRange', 'StaffDayMasks'] as $class) {
        foreach (PhpToken::tokenize((string) file_get_contents($directory.'/'.$class.'.php')) as $token) {
            if ($token->isIgnorable()) {
                continue;
            }

            if (preg_match('/status|expir|hold/i', $token->text) === 1) {
                $offences[] = $class.': '.$token->text;
            }
        }
    }

    expect($offences)->toBe([]);
});

it('refuses a pair of masks of different widths', function () {
    expect(fn () => new StaffDayMasks(FreeBusyMask::allSet(288), FreeBusyMask::allSet(276)))
        ->toThrow(InvalidArgumentException::class, 'The two masks of a staff-day share its width, not 288 and 276.');
});

it('refuses a staff id below one', function () {
    expect(fn () => (new MaskBuilder)->build(builderDay(), 0, [], [], []))
        ->toThrow(InvalidArgumentException::class, 'A staff id is a positive integer, not 0.');
});

it('builds the two clock-change days with the widths the geometry gives them', function () {
    $spring = saturdayMasks([builderBlackout(3, '2026-03-29', '12:00', '13:00')], [builderBlock('2026-03-29 01:00', '2026-03-29 05:00')], '2026-03-29');
    $autumn = saturdayMasks([], [builderBlock('2026-10-25 01:00', '2026-10-25 05:00')], '2026-10-25');

    expect($spring->open->width)->toBe(276)
        ->and($spring->open->setIndices())->toBe([...range(96, 131), ...range(144, 191)])
        ->and($spring->notBooked->setIndices())->toBe([...range(0, 11), ...range(48, 275)])
        ->and($autumn->notBooked->width)->toBe(300)
        ->and($autumn->notBooked->setIndices())->toBe([...range(0, 11), ...range(72, 299)]);
});

it('accepts any iterable for its three lists', function () {
    $masks = (new MaskBuilder)->build(
        builderDay(),
        3,
        builderGenerator([builderRule(6, '09:00', '17:00')]),
        builderGenerator([builderBlackout(3, '2026-03-28', '12:00', '13:00')]),
        builderGenerator([builderBlock('2026-03-28 09:00', '2026-03-28 10:00')]),
    );

    expect($masks->open->setIndices())->toBe([...range(108, 143), ...range(156, 203)])
        ->and($masks->notBooked->setIndices())->toBe([...range(0, 107), ...range(120, 287)]);
});

/*
 * A blackout window on a clock-change day covers every instant whose local reading falls inside
 * it: both passes of a repeated hour, whatever survives of a skipped one, and never an instant
 * whose reading lies outside the window under the offset in force at it. Rule windows keep
 * resolving once, as pinned above.
 */

it('closes only the surviving 04:00 to 04:15 for a 03:30 to 04:15 blackout on the spring day, when 03:30 to 04:00 never happens', function () {
    $masks = (new MaskBuilder)->build(builderDay('2026-03-29'), 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(3, '2026-03-29', '03:30', '04:15')], []);

    expect($masks->open->width)->toBe(276)
        ->and($masks->open->setIndices())->toBe([...range(0, 35), ...range(39, 275)]);
});

it('closes both passes of the repeated hour for a 03:00 to 04:00 blackout on the autumn day, tenant-wide or personal', function () {
    $personal = (new MaskBuilder)->build(builderDay('2026-10-25'), 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(3, '2026-10-25', '03:00', '04:00')], []);
    $tenantWide = (new MaskBuilder)->build(builderDay('2026-10-25'), 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(null, '2026-10-25', '03:00', '04:00')], []);

    expect($personal->open->width)->toBe(300)
        ->and($personal->open->setIndices())->toBe([...range(0, 35), ...range(60, 299)])
        ->and($tenantWide->open->setIndices())->toBe([...range(0, 35), ...range(60, 299)]);
});

it('closes both passes of the last hour in Asia/Beirut, whose clocks fall back at the stroke of midnight so the repeated hour is 23:00 to 24:00 of the Saturday', function () {
    // The tz data puts the 2026 autumn change at 2026-10-24T21:00Z: the Saturday is the 25-hour
    // day and 23:00 to 24:00 is read twice. The Sunday's midnight is read once, so a 00:00 to
    // 01:00 blackout on it is one ordinary hour on a plain day.
    $beirut = new DateTimeZone('Asia/Beirut');
    $saturday = StaffDayGeometry::of('2026-10-24', $beirut, Granularity::default());
    $sunday = StaffDayGeometry::of('2026-10-25', $beirut, Granularity::default());

    $lastHour = (new MaskBuilder)->build($saturday, 3, [builderRule(6, '00:00', '24:00')], [builderBlackout(3, '2026-10-24', '23:00', '24:00')], []);
    $firstHour = (new MaskBuilder)->build($sunday, 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(3, '2026-10-25', '00:00', '01:00')], []);

    expect($lastHour->open->width)->toBe(300)
        ->and($lastHour->open->setIndices())->toBe(range(0, 275))
        ->and($firstHour->open->width)->toBe(288)
        ->and($firstHour->open->setIndices())->toBe(range(12, 287));
});

it('closes nothing for a blackout window lying wholly inside the skipped hour, and the whole day for a whole-day blackout on a clock-change day', function () {
    $skipped = (new MaskBuilder)->build(builderDay('2026-03-29'), 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(3, '2026-03-29', '03:15', '03:45')], []);
    $wholeDay = (new MaskBuilder)->build(builderDay('2026-10-25'), 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(null, '2026-10-25')], []);

    expect($skipped->open->setIndices())->toBe(range(0, 275))
        ->and($wholeDay->open->setIndices())->toBe([]);
});

it('closes a blackout window on a day whose midnight the clocks skip only where its readings happen: 00:00 to 01:00 on Beirut\'s spring day closes nothing', function () {
    $day = StaffDayGeometry::of('2026-03-29', new DateTimeZone('Asia/Beirut'), Granularity::default());

    $masks = (new MaskBuilder)->build($day, 3, [builderRule(7, '00:00', '24:00')], [builderBlackout(3, '2026-03-29', '00:00', '01:00'), builderBlackout(3, '2026-03-29', '01:00', '01:30')], []);

    expect($masks->open->width)->toBe(276)
        ->and($masks->open->setIndices())->toBe(range(6, 275));
});

it('still resolves a blackout window on a plain day once, to the same slots as before', function () {
    $masks = saturdayMasks([builderBlackout(3, '2026-03-28', '12:03', '12:57'), builderBlackout(null, '2026-03-28', '16:00', '24:00')]);

    expect($masks->open->setIndices())->toBe([...range(108, 143), ...range(156, 191)]);
});

it('keeps a rule window resolving once on a clock-change day: 03:30 to 05:00 on the spring day still begins at 04:30', function () {
    $masks = (new MaskBuilder)->build(builderDay('2026-03-29'), 3, [builderRule(7, '03:30', '05:00')], [], []);

    expect($masks->open->setIndices())->toBe(range(42, 47));
});

it('is two halves build joins: open from the rules and blackouts, not-booked from the blocks, each callable alone', function () {
    $builder = new MaskBuilder;
    $day = builderDay();
    $rules = [builderRule(6, '09:00', '17:00')];
    $blackouts = [builderBlackout(3, '2026-03-28', '12:00', '13:00')];
    $blocks = [builderBlock('2026-03-28 09:03', '2026-03-28 09:17')];

    $both = $builder->build($day, 3, $rules, $blackouts, $blocks);

    expect($builder->open($day, 3, $rules, $blackouts)->equals($both->open))->toBeTrue()
        ->and($builder->notBooked($day, $blocks)->equals($both->notBooked))->toBeTrue()
        ->and($builder->open($day, 3, $rules, $blackouts)->setIndices())->toBe([...range(108, 143), ...range(156, 203)])
        ->and($builder->notBooked($day, [])->equals(FreeBusyMask::allSet(288)))->toBeTrue()
        ->and(fn () => $builder->open($day, 0, $rules, $blackouts))->toThrow(InvalidArgumentException::class, 'A staff id is a positive integer, not 0.');
});
