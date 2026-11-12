<?php

use App\Domain\Availability\DaySide;
use App\Domain\Availability\Granularity;
use App\Domain\Availability\InstantOutsideDay;
use App\Domain\Availability\StaffDayGeometry;

function athensDay(string $day, int $minutes = 5): StaffDayGeometry
{
    return StaffDayGeometry::of($day, new DateTimeZone('Europe/Athens'), Granularity::minutes($minutes));
}

it('is a calendar day in a zone at a granularity', function () {
    $day = athensDay('2026-03-28');

    expect($day->day)->toBe('2026-03-28')
        ->and($day->zone->getName())->toBe('Europe/Athens')
        ->and($day->granularity->minutes)->toBe(5);
});

it('is {width} slots wide on {day} in Athens at {minutes} minutes', function (string $day, int $minutes, int $width) {
    expect(athensDay($day, $minutes)->width)->toBe($width);
})->with([
    'an ordinary day at five' => ['2026-03-28', 5, 288],
    'the spring day at five' => ['2026-03-29', 5, 276],
    'the autumn day at five' => ['2026-10-25', 5, 300],
    'an ordinary day at fifteen' => ['2026-03-28', 15, 96],
    'the spring day at fifteen' => ['2026-03-29', 15, 92],
    'the autumn day at fifteen' => ['2026-10-25', 15, 100],
    'the spring day at one' => ['2026-03-29', 1, 1380],
    'the autumn day at sixty' => ['2026-10-25', 60, 25],
]);

it('is 288 slots wide on every day of the year in UTC', function () {
    $utc = new DateTimeZone('UTC');
    $widths = [];

    for ($cursor = new DateTimeImmutable('2026-01-01', $utc); $cursor->format('Y') === '2026'; $cursor = $cursor->modify('+1 day')) {
        $widths[] = StaffDayGeometry::of($cursor->format('Y-m-d'), $utc, Granularity::default())->width;
    }

    expect($widths)->toHaveCount(365)
        ->and(array_unique($widths))->toBe([288]);
});

it('spans the local midnight to the next local midnight, each with the offset in force at that instant', function () {
    $spring = athensDay('2026-03-29');
    $autumn = athensDay('2026-10-25');

    expect($spring->midnight->format(DATE_RFC3339))->toBe('2026-03-29T00:00:00+02:00')
        ->and($spring->nextMidnight->format(DATE_RFC3339))->toBe('2026-03-30T00:00:00+03:00')
        ->and($spring->nextMidnight->getTimestamp() - $spring->midnight->getTimestamp())->toBe(23 * 3600)
        ->and($autumn->midnight->format(DATE_RFC3339))->toBe('2026-10-25T00:00:00+03:00')
        ->and($autumn->nextMidnight->format(DATE_RFC3339))->toBe('2026-10-26T00:00:00+02:00')
        ->and($autumn->nextMidnight->getTimestamp() - $autumn->midnight->getTimestamp())->toBe(25 * 3600);
});

it('maps every index of {day} to an instant and back, both ways, in the tenant zone', function (string $day) {
    $geometry = athensDay($day);
    $roundTrips = 0;

    for ($index = 0; $index <= $geometry->width; $index++) {
        $instant = $geometry->instantAt($index);

        expect($instant->getTimezone()->getName())->toBe('Europe/Athens')
            ->and($instant->getTimestamp())->toBe($geometry->midnight->getTimestamp() + $index * 300)
            ->and($geometry->indexAtOrBefore($instant))->toBe($index)
            ->and($geometry->indexAtOrAfter($instant))->toBe($index);

        $roundTrips++;
    }

    expect($roundTrips)->toBe($geometry->width + 1);
})->with(['the spring day' => ['2026-03-29'], 'the autumn day' => ['2026-10-25']]);

it('gives the two instants one hour apart inside the repeated autumn hour two different indices', function () {
    $autumn = athensDay('2026-10-25');
    $utc = new DateTimeZone('UTC');
    $first = new DateTimeImmutable('2026-10-25T03:30:00+03:00', $utc);
    $second = new DateTimeImmutable('2026-10-25T03:30:00+02:00', $utc);

    expect($second->getTimestamp() - $first->getTimestamp())->toBe(3600)
        ->and($autumn->indexAtOrBefore($first))->toBe(42)
        ->and($autumn->indexAtOrBefore($second))->toBe(54)
        ->and($autumn->instantAt(42)->format('H:i P'))->toBe('03:30 +03:00')
        ->and($autumn->instantAt(54)->format('H:i P'))->toBe('03:30 +02:00');
});

it('renders the repeated autumn wall-clock hour twice and the missing spring hour not at all', function () {
    $hours = fn (StaffDayGeometry $geometry): array => array_count_values(array_map(
        fn (int $index): string => $geometry->instantAt($index)->format('H'),
        range(0, $geometry->width - 1),
    ));

    $autumn = $hours(athensDay('2026-10-25'));
    $spring = $hours(athensDay('2026-03-29'));

    expect($autumn['03'])->toBe(24)
        ->and($autumn['02'])->toBe(12)
        ->and($spring)->not->toHaveKey('03')
        ->and($spring['02'])->toBe(12)
        ->and($spring['04'])->toBe(12);
});

it('floors an instant between two slot starts to the earlier one and ceils it to the later one', function () {
    $day = athensDay('2026-03-28');
    $between = $day->midnight->modify('+7 minutes');
    $justPast = $day->midnight->modify('+5 minutes 1 microsecond');
    $exact = $day->midnight->modify('+5 minutes');

    expect($day->indexAtOrBefore($between))->toBe(1)
        ->and($day->indexAtOrAfter($between))->toBe(2)
        ->and($day->indexAtOrBefore($justPast))->toBe(1)
        ->and($day->indexAtOrAfter($justPast))->toBe(2)
        ->and($day->indexAtOrBefore($exact))->toBe(1)
        ->and($day->indexAtOrAfter($exact))->toBe(1);
});

it('reads only the position of an instant on the timeline, whatever zone it is spelled in', function () {
    $day = athensDay('2026-10-25');
    $inAthens = new DateTimeImmutable('2026-10-25T03:30:00+02:00', new DateTimeZone('UTC'));
    $inUtc = $inAthens->setTimezone(new DateTimeZone('UTC'));
    $inTokyo = $inAthens->setTimezone(new DateTimeZone('Asia/Tokyo'));

    expect($day->indexAtOrBefore($inUtc))->toBe(54)
        ->and($day->indexAtOrBefore($inTokyo))->toBe(54)
        ->and($day->side($inTokyo))->toBe(DaySide::Within);
});

it('places an instant before, within or after the day, with both midnights within it', function () {
    $day = athensDay('2026-03-28');

    expect($day->side($day->midnight->modify('-1 second')))->toBe(DaySide::Before)
        ->and($day->side($day->midnight))->toBe(DaySide::Within)
        ->and($day->side($day->midnight->modify('+12 hours')))->toBe(DaySide::Within)
        ->and($day->side($day->nextMidnight))->toBe(DaySide::Within)
        ->and($day->side($day->nextMidnight->modify('+1 microsecond')))->toBe(DaySide::After);
});

it('converts the next midnight to the width, the index that closes the last slot', function () {
    $day = athensDay('2026-03-29');

    expect($day->indexAtOrBefore($day->nextMidnight))->toBe(276)
        ->and($day->indexAtOrAfter($day->nextMidnight))->toBe(276)
        ->and($day->instantAt(276)->getTimestamp())->toBe($day->nextMidnight->getTimestamp())
        ->and($day->indexAtOrBefore($day->midnight))->toBe(0)
        ->and($day->indexAtOrAfter($day->midnight))->toBe(0);
});

it('refuses to convert an instant outside the day and says which side it fell on, never clamping', function () {
    $day = athensDay('2026-03-28');
    $before = $day->midnight->modify('-1 second');
    $after = $day->nextMidnight->modify('+1 second');

    foreach ([[$before, DaySide::Before], [$after, DaySide::After]] as [$instant, $side]) {
        foreach (['indexAtOrBefore', 'indexAtOrAfter'] as $conversion) {
            try {
                $day->{$conversion}($instant);
            } catch (InstantOutsideDay $e) {
                expect($e->side)->toBe($side)
                    ->and($e->instant)->toBe($instant)
                    ->and($e->day)->toBe('2026-03-28');

                continue;
            }

            $this->fail(sprintf('%s did not throw for an instant %s the day.', $conversion, strtolower($side->name)));
        }
    }

    expect(fn () => $day->indexAtOrBefore($before))
        ->toThrow(InstantOutsideDay::class, '2026-03-27T23:59:59+02:00 falls before the day 2026-03-28 in Europe/Athens.');
    expect(fn () => $day->indexAtOrAfter($after))
        ->toThrow(InstantOutsideDay::class, '2026-03-29T00:00:01+02:00 falls after the day 2026-03-28 in Europe/Athens.');
});

it('refuses an index below zero or above the width', function () {
    $day = athensDay('2026-03-28');

    expect(fn () => $day->instantAt(-1))->toThrow(InvalidArgumentException::class, 'An index on 2026-03-28 lies between 0 and 288, not -1.');
    expect(fn () => $day->instantAt(289))->toThrow(InvalidArgumentException::class, 'An index on 2026-03-28 lies between 0 and 288, not 289.');
});

it('refuses a day that is not spelled YYYY-MM-DD or is not a date: {day}', function (string $day) {
    expect(fn () => athensDay($day))->toThrow(InvalidArgumentException::class, sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
})->with([
    'a month and day without padding' => ['2026-1-1'],
    'a word' => ['today'],
    'a date with a time' => ['2026-10-01 10:00'],
    'the thirtieth of February' => ['2026-02-30'],
    'a thirteenth month' => ['2026-13-01'],
    'an empty string' => [''],
]);

it('starts at the first instant of a day whose local midnight the clocks skip', function () {
    $santiago = StaffDayGeometry::of('2024-09-08', new DateTimeZone('America/Santiago'), Granularity::default());

    expect($santiago->midnight->format(DATE_RFC3339))->toBe('2024-09-08T01:00:00-03:00')
        ->and($santiago->nextMidnight->format(DATE_RFC3339))->toBe('2024-09-09T00:00:00-03:00')
        ->and($santiago->width)->toBe(276);
});

it('refuses a day that is not a whole number of slots rather than rounding its width', function () {
    $lordHowe = new DateTimeZone('Australia/Lord_Howe');

    expect(StaffDayGeometry::of('2026-04-05', $lordHowe, Granularity::minutes(5))->width)->toBe(294)
        ->and(StaffDayGeometry::of('2026-04-05', $lordHowe, Granularity::minutes(30))->width)->toBe(49);
    expect(fn () => StaffDayGeometry::of('2026-04-05', $lordHowe, Granularity::minutes(60)))
        ->toThrow(InvalidArgumentException::class, 'The day 2026-04-05 in Australia/Lord_Howe is 1470 minutes long, not a whole number of 60-minute slots.');
});

it('runs on one offset for the whole of a plain day, from its midnight to its next midnight', function () {
    $periods = athensDay('2026-03-28')->offsetPeriods();

    expect($periods)->toHaveCount(1)
        ->and($periods[0]->offset)->toBe(7200)
        ->and($periods[0]->from->format(DATE_RFC3339))->toBe('2026-03-28T00:00:00+02:00')
        ->and($periods[0]->to->format(DATE_RFC3339))->toBe('2026-03-29T00:00:00+02:00');
});

it('splits a clock-change day into the stretch before the change and the stretch from it, each under its own offset', function () {
    $spring = athensDay('2026-03-29')->offsetPeriods();
    $autumn = athensDay('2026-10-25')->offsetPeriods();

    expect($spring)->toHaveCount(2)
        ->and($spring[0]->offset)->toBe(7200)
        ->and($spring[0]->from->getTimestamp())->toBe(athensDay('2026-03-29')->midnight->getTimestamp())
        ->and($spring[0]->to->format(DATE_RFC3339))->toBe('2026-03-29T04:00:00+03:00')
        ->and($spring[1]->offset)->toBe(10800)
        ->and($spring[1]->from->getTimestamp())->toBe($spring[0]->to->getTimestamp())
        ->and($spring[1]->to->getTimestamp())->toBe(athensDay('2026-03-29')->nextMidnight->getTimestamp())
        ->and($autumn)->toHaveCount(2)
        ->and($autumn[0]->offset)->toBe(10800)
        ->and($autumn[0]->to->format(DATE_RFC3339))->toBe('2026-10-25T03:00:00+02:00')
        ->and($autumn[1]->offset)->toBe(7200)
        ->and($autumn[1]->from->getTimestamp())->toBe($autumn[0]->to->getTimestamp())
        ->and($autumn[1]->to->getTimestamp())->toBe(athensDay('2026-10-25')->nextMidnight->getTimestamp());
});

it('runs on one offset when the clocks change exactly at the local midnight, whether that midnight is skipped or the day before repeated its last hour', function () {
    $beirutSpring = StaffDayGeometry::of('2026-03-29', new DateTimeZone('Asia/Beirut'), Granularity::default());
    $beirutSunday = StaffDayGeometry::of('2026-10-25', new DateTimeZone('Asia/Beirut'), Granularity::default());
    $beirutSaturday = StaffDayGeometry::of('2026-10-24', new DateTimeZone('Asia/Beirut'), Granularity::default());

    expect($beirutSpring->width)->toBe(276)
        ->and($beirutSpring->offsetPeriods())->toHaveCount(1)
        ->and($beirutSpring->offsetPeriods()[0]->offset)->toBe(10800)
        ->and($beirutSunday->width)->toBe(288)
        ->and($beirutSunday->offsetPeriods())->toHaveCount(1)
        ->and($beirutSaturday->width)->toBe(300)
        ->and($beirutSaturday->offsetPeriods())->toHaveCount(2)
        ->and($beirutSaturday->offsetPeriods()[1]->from->format(DATE_RFC3339))->toBe('2026-10-24T23:00:00+02:00');
});

it('clips a range of instants to an offset period, leaving an inverted pair for a range outside it', function () {
    $periods = athensDay('2026-10-25')->offsetPeriods();
    $utc = new DateTimeZone('UTC');
    $inside = $periods[0]->clip(new DateTimeImmutable('2026-10-24 23:00', $utc), new DateTimeImmutable('2026-10-25 00:30', $utc));
    $straddling = $periods[0]->clip(new DateTimeImmutable('2026-10-25 00:30', $utc), new DateTimeImmutable('2026-10-25 02:00', $utc));
    $outside = $periods[0]->clip(new DateTimeImmutable('2026-10-25 02:00', $utc), new DateTimeImmutable('2026-10-25 03:00', $utc));

    expect($inside[0]->format('H:i'))->toBe('23:00')
        ->and($inside[1]->format('H:i'))->toBe('00:30')
        ->and($straddling[0]->format('H:i'))->toBe('00:30')
        ->and($straddling[1]->getTimestamp())->toBe($periods[0]->to->getTimestamp())
        ->and($outside[0]->format('H:i'))->toBe('02:00')
        ->and($outside[1]->getTimestamp())->toBe($periods[0]->to->getTimestamp())
        ->and($outside[1] < $outside[0])->toBeTrue();
});
