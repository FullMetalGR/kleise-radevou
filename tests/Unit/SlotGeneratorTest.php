<?php

use App\Domain\Availability\AvailabilityRule;
use App\Domain\Availability\FreeBusyMask;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\MaskBuilder;
use App\Domain\Availability\SegmentShape;
use App\Domain\Availability\SlotGenerator;
use App\Domain\Availability\WallClockTime;
use App\Domain\Availability\WindowGeometry;
use App\Domain\Availability\WindowMasks;

function generatorWindow(string $firstDay = '2026-03-28', int $dayCount = 3): WindowGeometry
{
    return WindowGeometry::spanning($firstDay, $dayCount, new DateTimeZone('Europe/Athens'), Granularity::default());
}

function generatorInstant(string $text, string $zone = 'Europe/Athens'): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

/**
 * A generator over the window with every slot a start unless a mask is given, now and today
 * as spelled, and a lead time and horizon that drop nothing unless given.
 */
function generatorAt(WindowGeometry $window, string $now, string $today, int $leadTimeMin = 0, ?int $horizonDays = 60, ?FreeBusyMask $starts = null): SlotGenerator
{
    return new SlotGenerator($window, $starts ?? FreeBusyMask::allSet($window->width), generatorInstant($now), $today, $leadTimeMin, $horizonDays);
}

/**
 * @param  list<DateTimeImmutable>  $starts
 * @return list<string>
 */
function generatorTimes(array $starts, string $format = 'Y-m-d H:i'): array
{
    return array_map(fn (DateTimeImmutable $start): string => $start->format($format), $starts);
}

/**
 * @param  list<DateTimeImmutable>  $starts
 * @return list<int>
 */
function generatorTimestamps(array $starts): array
{
    return array_map(fn (DateTimeImmutable $start): int => $start->getTimestamp(), $starts);
}

it('answers the starts of one date in order, as instants in the tenant zone, from now on when the lead time is zero', function () {
    $generator = generatorAt(generatorWindow(), '2026-03-28 10:02:30', '2026-03-28');
    $starts = $generator->startsOn('2026-03-28');
    $times = generatorTimes($starts);

    expect($starts)->toHaveCount(167)
        ->and($times[0])->toBe('2026-03-28 10:05')
        ->and($times[166])->toBe('2026-03-28 23:55')
        ->and($times)->not->toContain('2026-03-28 10:00')
        ->and($starts[0]->getTimezone()->getName())->toBe('Europe/Athens')
        ->and($starts[0]->format(DATE_RFC3339))->toBe('2026-03-28T10:05:00+02:00');

    $sorted = $starts;
    usort($sorted, fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);

    expect($starts)->toBe($sorted);
});

it('reads now by its position on the timeline, whatever zone it is spelled in, and still answers in the tenant zone', function () {
    $window = generatorWindow();
    $inUtc = new SlotGenerator($window, FreeBusyMask::allSet($window->width), generatorInstant('2026-03-28 08:02:30', 'UTC'), '2026-03-28', 0, 60);

    expect(generatorTimes($inUtc->startsOn('2026-03-28')))->toBe(generatorTimes(generatorAt($window, '2026-03-28 10:02:30', '2026-03-28')->startsOn('2026-03-28')))
        ->and($inUtc->startsOn('2026-03-28')[0]->getTimezone()->getName())->toBe('Europe/Athens');
});

it('cuts at the same instant for a lead time whether now is spelled in UTC or in the tenant zone, on both clock-change days', function () {
    $cases = [
        ['2026-03-29', '2026-03-29 00:30:00', '2026-03-29 02:30:00', '2026-03-29T05:30:00+03:00'],
        ['2026-10-25', '2026-10-24 23:30:00', '2026-10-25 02:30:00', '2026-10-25T03:30:00+02:00'],
    ];

    foreach ($cases as [$day, $nowInUtc, $nowInAthens, $first]) {
        $window = generatorWindow($day, 1);
        $spelledInUtc = new SlotGenerator($window, FreeBusyMask::allSet($window->width), generatorInstant($nowInUtc, 'UTC'), $day, 120, 60);
        $spelledInAthens = generatorAt($window, $nowInAthens, $day, 120);

        expect(generatorInstant($nowInUtc, 'UTC')->getTimestamp())->toBe(generatorInstant($nowInAthens)->getTimestamp())
            ->and(generatorTimestamps($spelledInUtc->startsOn($day)))->toBe(generatorTimestamps($spelledInAthens->startsOn($day)))
            ->and($spelledInUtc->startsOn($day)[0]->format(DATE_RFC3339))->toBe($first)
            ->and($spelledInAthens->startsOn($day)[0]->format(DATE_RFC3339))->toBe($first)
            ->and($spelledInUtc->startsOn($day)[0]->getTimezone()->getName())->toBe('Europe/Athens');
    }
});

it('drops starts earlier than now plus the lead time, keeps one exactly there, and reads now to the microsecond', function () {
    $window = generatorWindow();

    expect(generatorTimes(generatorAt($window, '2026-03-28 10:02:30', '2026-03-28', 60)->startsOn('2026-03-28'))[0])->toBe('2026-03-28 11:05')
        ->and(generatorTimes(generatorAt($window, '2026-03-28 10:00:00', '2026-03-28', 30)->startsOn('2026-03-28'))[0])->toBe('2026-03-28 10:30')
        ->and(generatorTimes(generatorAt($window, '2026-03-28 10:00:00', '2026-03-28')->startsOn('2026-03-28'))[0])->toBe('2026-03-28 10:00')
        ->and(generatorTimes(generatorAt($window, '2026-03-28 10:00:00.000001', '2026-03-28')->startsOn('2026-03-28'))[0])->toBe('2026-03-28 10:05');
});

it('counts the lead time in elapsed minutes across the spring change: two hours after 02:30+02:00 on 2026-03-29 is 05:30+03:00, and 04:30 is dropped', function () {
    $now = generatorInstant('2026-03-29 02:30:00');
    $starts = generatorAt(generatorWindow('2026-03-29', 1), '2026-03-29 02:30:00', '2026-03-29', 120)->startsOn('2026-03-29');
    $instants = generatorTimes($starts, DATE_RFC3339);

    expect($now->format(DATE_RFC3339))->toBe('2026-03-29T02:30:00+02:00')
        ->and($instants[0])->toBe('2026-03-29T05:30:00+03:00')
        ->and($starts[0]->getTimestamp())->toBe($now->getTimestamp() + 120 * 60)
        ->and($instants)->not->toContain('2026-03-29T04:30:00+03:00')
        ->and($instants)->not->toContain('2026-03-29T05:25:00+03:00')
        ->and($starts)->toHaveCount(276 - 54);
});

it('counts the lead time in elapsed minutes across the autumn change: two hours after 02:30+03:00 on 2026-10-25 is 03:30+02:00, and the starts from there on are offered', function () {
    $now = generatorInstant('2026-10-25 02:30:00');
    $starts = generatorAt(generatorWindow('2026-10-25', 1), '2026-10-25 02:30:00', '2026-10-25', 120)->startsOn('2026-10-25');
    $instants = generatorTimes($starts, DATE_RFC3339);

    expect($now->format(DATE_RFC3339))->toBe('2026-10-25T02:30:00+03:00')
        ->and($instants[0])->toBe('2026-10-25T03:30:00+02:00')
        ->and($starts[0]->getTimestamp())->toBe($now->getTimestamp() + 120 * 60)
        ->and($instants)->not->toContain('2026-10-25T03:30:00+03:00')
        ->and($instants)->not->toContain('2026-10-25T03:25:00+02:00')
        ->and($instants)->toContain('2026-10-25T03:35:00+02:00')
        ->and($instants)->toContain('2026-10-25T04:25:00+02:00')
        ->and($starts)->toHaveCount(300 - 54);
});

it('drops starts on a tenant-local date later than today plus the horizon, counted in calendar days and not in hours from now', function () {
    $window = generatorWindow();

    $none = generatorAt($window, '2026-03-28 22:00', '2026-03-28', 0, 0);
    $one = generatorAt($window, '2026-03-28 22:00', '2026-03-28', 0, 1);
    $two = generatorAt($window, '2026-03-28 22:00', '2026-03-28', 0, 2);

    expect($none->datesWithStarts())->toBe(['2026-03-28'])
        ->and($none->startsOn('2026-03-28'))->toHaveCount(24)
        ->and($none->startsOn('2026-03-29'))->toBe([])
        ->and($one->datesWithStarts())->toBe(['2026-03-28', '2026-03-29'])
        ->and($one->startsOn('2026-03-29'))->toHaveCount(276)
        ->and(generatorTimes($one->startsOn('2026-03-29')))->toContain('2026-03-29 23:55')
        ->and($one->startsOn('2026-03-30'))->toBe([])
        ->and($two->datesWithStarts())->toBe(['2026-03-28', '2026-03-29', '2026-03-30']);
});

it('drops no later date when there is no horizon, the read the business is given, while the past is still gone', function () {
    $window = generatorWindow();
    $generator = generatorAt($window, '2026-03-28 22:00', '2026-03-28', 0, null);

    expect($generator->datesWithStarts())->toBe(['2026-03-28', '2026-03-29', '2026-03-30'])
        ->and($generator->startsOn('2026-03-28'))->toHaveCount(24)
        ->and($generator->startsOn('2026-03-30'))->toHaveCount(288);
});

it('measures the horizon from the tenant\'s today it is given, not from the date of now', function () {
    $window = generatorWindow();
    $generator = generatorAt($window, '2026-03-27 12:00', '2026-03-29', 0, 0);

    expect($generator->datesWithStarts())->toBe(['2026-03-28', '2026-03-29'])
        ->and($generator->startsOn('2026-03-30'))->toBe([]);
});

it('answers the dates with at least one start, leaving out a date whose starts are absent or all in the past', function () {
    $window = generatorWindow();
    $starts = FreeBusyMask::allClear($window->width)->set(100, 101)->set(600, 601);

    expect(generatorAt($window, '2026-03-27 12:00', '2026-03-27', 0, 60, $starts)->datesWithStarts())->toBe(['2026-03-28', '2026-03-30'])
        ->and(generatorAt($window, '2026-03-28 12:00', '2026-03-28', 0, 60, $starts)->datesWithStarts())->toBe(['2026-03-30'])
        ->and(generatorAt($window, '2026-03-28 12:00', '2026-03-28', 0, 60, $starts)->startsOn('2026-03-28'))->toBe([])
        ->and(generatorTimes(generatorAt($window, '2026-03-27 12:00', '2026-03-27', 0, 60, $starts)->startsOn('2026-03-28')))->toBe(['2026-03-28 08:20'])
        ->and(generatorAt($window, '2026-03-31 12:00', '2026-03-31', 0, 60, $starts)->datesWithStarts())->toBe([]);
});

it('has no instant for a wall time inside the missing hour on 2026-03-29 in Athens', function () {
    $starts = generatorAt(generatorWindow('2026-03-29', 1), '2026-03-28 12:00', '2026-03-28')->startsOn('2026-03-29');
    $hours = array_count_values(generatorTimes($starts, 'H'));
    $times = generatorTimes($starts, 'H:i P');

    expect($starts)->toHaveCount(276)
        ->and($hours)->not->toHaveKey('03')
        ->and($hours['02'])->toBe(12)
        ->and($hours['04'])->toBe(12)
        ->and($times[35])->toBe('02:55 +02:00')
        ->and($times[36])->toBe('04:00 +03:00');
});

it('yields one distinct instant per real slot through the repeated hour on 2026-10-25 in Athens', function () {
    $starts = generatorAt(generatorWindow('2026-10-25', 1), '2026-10-24 12:00', '2026-10-24')->startsOn('2026-10-25');
    $times = generatorTimes($starts, 'H:i P');
    $timestamps = array_map(fn (DateTimeImmutable $start): int => $start->getTimestamp(), $starts);

    expect($starts)->toHaveCount(300)
        ->and(array_unique($timestamps))->toHaveCount(300)
        ->and(array_count_values(generatorTimes($starts, 'H'))['03'])->toBe(24)
        ->and(array_count_values($times)['03:30 +03:00'])->toBe(1)
        ->and(array_count_values($times)['03:30 +02:00'])->toBe(1)
        ->and(array_search('03:30 +03:00', $times, true))->toBe(42)
        ->and(array_search('03:30 +02:00', $times, true))->toBe(54);
});

it('turns a real day of rules and a segment shape into the times a customer is shown, end to end', function () {
    $window = generatorWindow('2026-03-28', 1);
    $rules = array_map(fn (int $weekday): AvailabilityRule => new AvailabilityRule($weekday, WallClockTime::of('09:00'), WallClockTime::of('18:00')), range(1, 7));
    $masks = WindowMasks::of($window, array_map(fn ($day) => (new MaskBuilder)->build($day, 3, $rules, [], []), $window->days));
    $starts = $masks->segmentStarts(new SegmentShape(60, 0, 15));

    $early = generatorTimes(generatorAt($window, '2026-03-28 08:00', '2026-03-28', 30, 60, $starts)->startsOn('2026-03-28'), 'H:i');
    $late = generatorTimes(generatorAt($window, '2026-03-28 09:12', '2026-03-28', 30, 60, $starts)->startsOn('2026-03-28'), 'H:i');

    expect($early)->toHaveCount(97)
        ->and($early[0])->toBe('09:00')
        ->and($early[96])->toBe('17:00')
        ->and($late[0])->toBe('09:45')
        ->and($late)->toHaveCount(88);
});

it('refuses a start mask that is not the width of the window', function () {
    $window = generatorWindow();

    expect(fn () => new SlotGenerator($window, FreeBusyMask::allSet(288), generatorInstant('2026-03-28 12:00'), '2026-03-28', 0, 60))
        ->toThrow(InvalidArgumentException::class, 'A start mask is the width of its window, 852, not 288.');
});

it('refuses a day outside the window, a negative lead time, a negative horizon and a today that is not a day', function () {
    $window = generatorWindow();

    expect(fn () => generatorAt($window, '2026-03-28 12:00', '2026-03-28')->startsOn('2026-03-31'))
        ->toThrow(InvalidArgumentException::class, 'The window from 2026-03-28 to 2026-03-30 has no day 2026-03-31.');
    expect(fn () => generatorAt($window, '2026-03-28 12:00', '2026-03-28', -1))
        ->toThrow(InvalidArgumentException::class, 'A lead time is a number of minutes at or above zero, not -1.');
    expect(fn () => generatorAt($window, '2026-03-28 12:00', '2026-03-28', 0, -1))
        ->toThrow(InvalidArgumentException::class, 'A horizon is a number of days at or above zero, not -1.');
    expect(fn () => generatorAt($window, '2026-03-28 12:00', 'today'))
        ->toThrow(InvalidArgumentException::class, 'A day is spelled YYYY-MM-DD, not "today".');
});
