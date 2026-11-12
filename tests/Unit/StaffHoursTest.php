<?php

use App\Application\Dto\CalendarWho;
use App\Application\Dto\CalendarWindow;
use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\ShiftOverride;
use App\Domain\Availability\ShiftPattern;
use App\Domain\Availability\ShiftResolver;
use App\Domain\Availability\WallClockTime;
use App\Infrastructure\Db\StaffHours;

/*
 * The Calendar's hours reader's pure half: the windows per member per date are the ones
 * RuleExpander::inForce answers over the resolver's windows given, the closures the dates'
 * blackouts that reach the member, and Scheduled staff the members with a window on some date.
 */

function hoursRule(int $weekday, string $start, string $end): AvailabilityRule
{
    return new AvailabilityRule($weekday, WallClockTime::of($start), WallClockTime::of($end));
}

/**
 * Each member's windows per date as MasksFromRows::windowsOf hands them over: resolved through
 * ShiftResolver from the member's pattern and overrides.
 *
 * @param  list<string>  $days
 * @param  array<int, array{ShiftPattern|null, array<string, ShiftOverride>}>  $members
 * @return array<int, array<string, list<AvailabilityRule>>>
 */
function hoursResolved(array $days, array $members): array
{
    $windows = [];

    foreach ($members as $staffId => [$pattern, $overrides]) {
        foreach ($days as $day) {
            $windows[$staffId][$day] = (new ShiftResolver)->windowsOn($pattern, $overrides, 1, $day);
        }
    }

    return $windows;
}

it('answers every member every date, the resolver\'s windows in their order spelled HH:MM with the end of day as 24:00, a date past the pattern\'s end left out and an override standing in', function () {
    // Monday 2026-11-02 to Wednesday 2026-11-04.
    $days = (new CalendarWindow('2026-11-02', '2026-11-05'))->days();
    $windows = hoursResolved($days, [
        3 => [new ShiftPattern('2026-11-02', 1, null, [hoursRule(1, '09:00:00', '13:00:00'), hoursRule(1, '14:00:00', '24:00:00'), hoursRule(3, '10:00:00', '12:00:00')]), ['2026-11-04' => ShiftOverride::of('2026-11-04', [])]],
        8 => [new ShiftPattern('2026-10-26', 1, '2026-11-03', [hoursRule(2, '08:30:00', '17:15:00'), hoursRule(3, '08:30:00', '17:15:00')]), []],
        12 => [null, []],
    ]);

    $hours = StaffHours::assemble($days, [3, 8, 12], $windows, []);
    $empty = ['windows' => [], 'closures' => []];

    expect($days)->toBe(['2026-11-02', '2026-11-03', '2026-11-04'])
        ->and($hours)->toBe([
            3 => [
                '2026-11-02' => ['windows' => [['start' => '09:00', 'end' => '13:00'], ['start' => '14:00', 'end' => '24:00']], 'closures' => []],
                '2026-11-03' => $empty,
                '2026-11-04' => $empty,
            ],
            8 => [
                '2026-11-02' => $empty,
                '2026-11-03' => ['windows' => [['start' => '08:30', 'end' => '17:15']], 'closures' => []],
                '2026-11-04' => $empty,
            ],
            12 => ['2026-11-02' => $empty, '2026-11-03' => $empty, '2026-11-04' => $empty],
        ])
        ->and(StaffHours::scheduled($hours))->toBe([3, 8]);
});

it('hands each member the closures of the date that reach them, the business\'s to everyone and a member\'s to that member alone, and a closure schedules nobody', function () {
    $closures = [
        ['staff_id' => null, 'date' => '2026-12-25', 'start' => null, 'end' => null, 'reason' => 'Christmas Day'],
        ['staff_id' => 4, 'date' => '2026-12-25', 'start' => '14:00', 'end' => '16:00', 'reason' => null],
        ['staff_id' => 5, 'date' => '2026-12-26', 'start' => null, 'end' => null, 'reason' => 'Away'],
    ];

    $hours = StaffHours::assemble(['2026-12-25'], [4, 5], [], $closures);

    expect($hours)->toBe([
        4 => ['2026-12-25' => ['windows' => [], 'closures' => [
            ['start' => null, 'end' => null, 'reason' => 'Christmas Day', 'business' => true],
            ['start' => '14:00', 'end' => '16:00', 'reason' => null, 'business' => false],
        ]]],
        5 => ['2026-12-25' => ['windows' => [], 'closures' => [
            ['start' => null, 'end' => null, 'reason' => 'Christmas Day', 'business' => true],
        ]]],
    ])
        ->and(StaffHours::scheduled($hours))->toBe([]);
});

it('keeps the window\'s Who and its staff list sorted and unique, narrowing in every mode but All', function () {
    $picked = new CalendarWindow('2026-11-02', '2026-11-09', CalendarWho::Picked, [9, 2, 9, 5]);

    expect($picked->staff)->toBe([2, 5, 9])
        ->and($picked->narrows())->toBeTrue()
        ->and($picked->narrowedTo([5])->staff)->toBe([5])
        ->and($picked->narrowedTo([5])->who)->toBe(CalendarWho::Picked)
        ->and((new CalendarWindow('2026-11-02', '2026-11-09', CalendarWho::All))->narrows())->toBeFalse()
        ->and(CalendarWho::spelled('me'))->toBe(CalendarWho::Me)
        ->and(CalendarWho::spelled('all'))->toBe(CalendarWho::All)
        ->and(CalendarWho::spelled('picked'))->toBe(CalendarWho::Scheduled)
        ->and(CalendarWho::spelled('anyone'))->toBe(CalendarWho::Scheduled)
        ->and(CalendarWho::spelled(null))->toBe(CalendarWho::Scheduled);
});

it('names each member\'s first date in order holding a window no whole-day closure takes, with its windows, a closure of hours leaving the day, and null for a member with none', function () {
    $nine = [['start' => '09:00', 'end' => '13:00']];
    $none = [];
    $leave = [['start' => null, 'end' => null, 'reason' => 'Leave', 'business' => false]];
    $errand = [['start' => '10:00', 'end' => '11:00', 'reason' => 'Errand', 'business' => false]];
    $hours = [
        2 => [
            '2026-11-11' => ['windows' => $nine, 'closures' => $none],
            '2026-11-09' => ['windows' => $nine, 'closures' => $leave],
            '2026-11-10' => ['windows' => $none, 'closures' => $none],
        ],
        4 => [
            '2026-11-09' => ['windows' => $none, 'closures' => $none],
            '2026-11-10' => ['windows' => $nine, 'closures' => $errand],
        ],
        6 => [
            '2026-11-09' => ['windows' => $none, 'closures' => $none],
        ],
    ];

    expect(StaffHours::firstWorking([2, 4, 6, 8], $hours))->toBe([
        2 => ['date' => '2026-11-11', 'windows' => $nine],
        4 => ['date' => '2026-11-10', 'windows' => $nine],
        6 => null,
        8 => null,
    ]);
});
