<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\RuleExpander;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\WallClockTime;

function expanderDay(string $day, int $minutes = 5): StaffDayGeometry
{
    return StaffDayGeometry::of($day, new DateTimeZone('Europe/Athens'), Granularity::minutes($minutes));
}

function expanderRule(int $weekday, string $start, string $end, ?string $validFrom = null, ?string $validTo = null): AvailabilityRule
{
    return new AvailabilityRule($weekday, WallClockTime::of($start), WallClockTime::of($end), $validFrom, $validTo);
}

it('answers the rules in force on a day, in the order given: the weekday matches and the date lies inside the validity range', function () {
    $sundayAlways = expanderRule(7, '09:00', '17:00');
    $sundayInMarch = expanderRule(7, '18:00', '20:00', '2026-03-01', '2026-03-31');
    $sundayFromApril = expanderRule(7, '18:00', '20:00', '2026-04-01', null);
    $sundayUntilFebruary = expanderRule(7, '18:00', '20:00', null, '2026-02-28');
    $saturday = expanderRule(6, '09:00', '17:00');

    $rules = [$saturday, $sundayFromApril, $sundayAlways, $sundayInMarch, $sundayUntilFebruary];

    expect((new RuleExpander)->inForce('2026-03-29', $rules))->toBe([$sundayAlways, $sundayInMarch])
        ->and((new RuleExpander)->inForce('2026-04-05', $rules))->toBe([$sundayFromApril, $sundayAlways])
        ->and((new RuleExpander)->inForce('2026-03-28', $rules))->toBe([$saturday])
        ->and((new RuleExpander)->inForce('2026-03-30', $rules))->toBe([]);
});

it('sets the slots of one window in an otherwise empty mask', function () {
    $mask = (new RuleExpander)->expand(expanderDay('2026-03-28'), [expanderRule(6, '09:00', '17:00')]);

    expect($mask->width)->toBe(288)
        ->and($mask->setIndices())->toBe(range(108, 203));
});

it('yields an empty mask with no rules, and with rules for other weekdays only', function () {
    $day = expanderDay('2026-03-28');

    expect((new RuleExpander)->expand($day, [])->setIndices())->toBe([])
        ->and((new RuleExpander)->expand($day, [expanderRule(7, '09:00', '17:00'), expanderRule(1, '09:00', '17:00')])->setIndices())->toBe([]);
});

it('unions several rules on one weekday: a split day around lunch is two runs with the lunch slots clear', function () {
    $mask = (new RuleExpander)->expand(expanderDay('2026-03-28'), [
        expanderRule(6, '09:00', '12:00'),
        expanderRule(6, '13:00', '17:00'),
    ]);

    expect($mask->setIndices())->toBe([...range(108, 143), ...range(156, 203)])
        ->and($mask->isSet(143))->toBeTrue()
        ->and($mask->isSet(144))->toBeFalse()
        ->and($mask->isSet(155))->toBeFalse()
        ->and($mask->isSet(156))->toBeTrue();
});

it('unions overlapping windows into one', function () {
    $mask = (new RuleExpander)->expand(expanderDay('2026-03-28'), [
        expanderRule(6, '09:00', '13:00'),
        expanderRule(6, '11:00', '17:00'),
        expanderRule(6, '10:00', '10:30'),
    ]);

    expect($mask->setIndices())->toBe(range(108, 203));
});

it('yields 36 free slots from a 01:00 to 05:00 window on the spring day and 60 on the autumn day, both starting at 01:00 local', function () {
    $spring = expanderDay('2026-03-29');
    $autumn = expanderDay('2026-10-25');
    $rule = expanderRule(7, '01:00', '05:00');

    $springMask = (new RuleExpander)->expand($spring, [$rule]);
    $autumnMask = (new RuleExpander)->expand($autumn, [$rule]);

    expect($springMask->setIndices())->toHaveCount(36)
        ->and($springMask->setIndices())->toBe(range(12, 47))
        ->and($spring->instantAt(12)->format('H:i P'))->toBe('01:00 +02:00')
        ->and($spring->instantAt(48)->format('H:i P'))->toBe('05:00 +03:00')
        ->and($autumnMask->setIndices())->toHaveCount(60)
        ->and($autumnMask->setIndices())->toBe(range(12, 71))
        ->and($autumn->instantAt(12)->format('H:i P'))->toBe('01:00 +03:00')
        ->and($autumn->instantAt(72)->format('H:i P'))->toBe('05:00 +02:00');
});

it('yields 96 free slots from a 09:00 to 17:00 window on both clock-change days, starting at 09:00 local', function () {
    $rule = expanderRule(7, '09:00', '17:00');

    foreach (['2026-03-29' => 96, '2026-10-25' => 120] as $day => $first) {
        $geometry = expanderDay($day);
        $mask = (new RuleExpander)->expand($geometry, [$rule]);

        expect($mask->setIndices())->toHaveCount(96)
            ->and($mask->setIndices()[0])->toBe($first)
            ->and($geometry->instantAt($first)->format('H:i'))->toBe('09:00')
            ->and($geometry->instantAt($first + 96)->format('H:i'))->toBe('17:00');
    }
});

it('pins a window starting inside the spring gap to the instant PHP resolves it to: 03:30 to 05:00 begins at 04:30 in the new offset', function () {
    // Behaviour pinned, not preferred. The reading 03:30 does not exist on 2026-03-29 in
    // Athens; the parser lands it an hour later, and the window is what follows from that.
    $spring = expanderDay('2026-03-29');
    $mask = (new RuleExpander)->expand($spring, [expanderRule(7, '03:30', '05:00')]);

    expect($mask->setIndices())->toBe(range(42, 47))
        ->and($spring->instantAt(42)->format('H:i P'))->toBe('04:30 +03:00');
});

it('pins that a window lying wholly inside the spring gap, or straddling its end, yields nothing', function () {
    // Both readings of 03:00 to 04:00 resolve to 04:00 in the new offset, and 03:30 to 04:15
    // resolves to 04:30 then 04:15, the end before the start. Either way there is no window,
    // and the day builds rather than raising over a rule that names an hour it does not have.
    $spring = expanderDay('2026-03-29');

    expect((new RuleExpander)->expand($spring, [expanderRule(7, '03:00', '04:00')])->setIndices())->toBe([])
        ->and((new RuleExpander)->expand($spring, [expanderRule(7, '03:30', '04:15')])->setIndices())->toBe([]);
});

it('fills the last slot of the day from a window closing at 24:00', function () {
    $mask = (new RuleExpander)->expand(expanderDay('2026-03-28'), [expanderRule(6, '20:00', '24:00')]);

    expect($mask->setIndices())->toBe(range(240, 287));
});

it('rounds a window inward to the grid, so a member on duty from 09:03 to 17:02 is offered 09:05 to 17:00', function () {
    $mask = (new RuleExpander)->expand(expanderDay('2026-03-28'), [expanderRule(6, '09:03', '17:02')]);

    expect($mask->setIndices())->toBe(range(109, 203));
});

it('expands at any granularity the day divides into', function () {
    $mask = (new RuleExpander)->expand(expanderDay('2026-03-28', 15), [expanderRule(6, '09:00', '17:00')]);

    expect($mask->width)->toBe(96)
        ->and($mask->setIndices())->toBe(range(36, 67));
});

it('takes no staff member: the expander cannot tell opening hours from a member\'s own rules', function () {
    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionMethod(RuleExpander::class, 'expand'))->getParameters(),
    );

    expect($parameters)->toBe(['geometry', 'rules']);
});
