<?php

use App\Domain\Availability\Granularity;
use App\Domain\Availability\StaffDayGeometry;
use App\Domain\Availability\WindowGeometry;

function windowDay(string $day, string $zone = 'Europe/Athens', int $minutes = 5): StaffDayGeometry
{
    return StaffDayGeometry::of($day, new DateTimeZone($zone), Granularity::minutes($minutes));
}

/**
 * A window of consecutive days in Athens at five minutes. Three days from the Saturday cross
 * the spring clock change, so the widths are 288, 276 and 288 and the offsets 0, 288 and 564.
 */
function athensWindow(string $firstDay = '2026-03-28', int $dayCount = 3): WindowGeometry
{
    return WindowGeometry::spanning($firstDay, $dayCount, new DateTimeZone('Europe/Athens'), Granularity::default());
}

/**
 * @template T
 *
 * @param  list<T>  $items
 * @return Generator<int, T>
 */
function windowGenerator(array $items): Generator
{
    yield from $items;
}

it('spans a number of consecutive days from a first day, each with its own width, and is their widths added up', function () {
    $window = athensWindow();

    expect(array_map(fn (StaffDayGeometry $day): string => $day->day, $window->days))->toBe(['2026-03-28', '2026-03-29', '2026-03-30'])
        ->and(array_map(fn (StaffDayGeometry $day): int => $day->width, $window->days))->toBe([288, 276, 288])
        ->and($window->width)->toBe(852)
        ->and($window->zone->getName())->toBe('Europe/Athens')
        ->and($window->granularity->minutes)->toBe(5);
});

it('is built from a list of staff-days as well, any iterable, and comes out the same', function () {
    $fromList = WindowGeometry::of([windowDay('2026-03-28'), windowDay('2026-03-29'), windowDay('2026-03-30')]);
    $fromGenerator = WindowGeometry::of(windowGenerator([windowDay('2026-03-28'), windowDay('2026-03-29'), windowDay('2026-03-30')]));

    expect($fromList)->toEqual(athensWindow())
        ->and($fromGenerator)->toEqual(athensWindow())
        ->and(WindowGeometry::of([windowDay('2026-03-29')])->width)->toBe(276);
});

it('knows the window index of each day\'s first slot, the geometry of each day by name, and which days it holds', function () {
    $window = athensWindow();

    expect($window->offsetOf('2026-03-28'))->toBe(0)
        ->and($window->offsetOf('2026-03-29'))->toBe(288)
        ->and($window->offsetOf('2026-03-30'))->toBe(564)
        ->and($window->dayFor('2026-03-28'))->toBe($window->days[0])
        ->and($window->dayFor('2026-03-29'))->toBe($window->days[1])
        ->and($window->dayFor('2026-03-29')->day)->toBe('2026-03-29')
        ->and($window->dayFor('2026-03-29')->width)->toBe(276)
        ->and($window->dayFor('2026-03-30'))->toBe($window->days[2])
        ->and($window->contains('2026-03-30'))->toBeTrue()
        ->and($window->contains('2026-03-31'))->toBeFalse()
        ->and($window->contains('2026-03-27'))->toBeFalse();
});

it('refuses the offset or the geometry of a day outside the window', function () {
    $window = athensWindow();

    expect(fn () => $window->offsetOf('2026-03-31'))->toThrow(InvalidArgumentException::class, 'The window from 2026-03-28 to 2026-03-30 has no day 2026-03-31.');
    expect(fn () => $window->offsetOf('2026-03-27'))->toThrow(InvalidArgumentException::class, 'The window from 2026-03-28 to 2026-03-30 has no day 2026-03-27.');
    expect(fn () => $window->offsetOf('today'))->toThrow(InvalidArgumentException::class, 'The window from 2026-03-28 to 2026-03-30 has no day today.');
    expect(fn () => $window->dayFor('2026-03-31'))->toThrow(InvalidArgumentException::class, 'The window from 2026-03-28 to 2026-03-30 has no day 2026-03-31.');
    expect(fn () => $window->dayFor('today'))->toThrow(InvalidArgumentException::class, 'The window from 2026-03-28 to 2026-03-30 has no day today.');
});

it('walks a window index back to the day it falls in, the day boundaries included', function () {
    $window = athensWindow();

    expect($window->dayAt(0)->day)->toBe('2026-03-28')
        ->and($window->dayAt(287)->day)->toBe('2026-03-28')
        ->and($window->dayAt(288)->day)->toBe('2026-03-29')
        ->and($window->dayAt(563)->day)->toBe('2026-03-29')
        ->and($window->dayAt(564)->day)->toBe('2026-03-30')
        ->and($window->dayAt(851)->day)->toBe('2026-03-30')
        ->and(WindowGeometry::of([windowDay('2026-03-29')])->dayAt(275)->day)->toBe('2026-03-29');
});

it('refuses a slot index below zero or at the width, which is a position and not a slot', function () {
    $window = athensWindow();

    expect(fn () => $window->dayAt(-1))->toThrow(InvalidArgumentException::class, 'A slot index on a window of width 852 lies between 0 and 851, not -1.');
    expect(fn () => $window->dayAt(852))->toThrow(InvalidArgumentException::class, 'A slot index on a window of width 852 lies between 0 and 851, not 852.');
});

it('converts a window index to an instant in the tenant zone through the day it falls in, the width being the next midnight after the last day', function () {
    $window = athensWindow();

    expect($window->instantAt(0)->format(DATE_RFC3339))->toBe('2026-03-28T00:00:00+02:00')
        ->and($window->instantAt(287)->format(DATE_RFC3339))->toBe('2026-03-28T23:55:00+02:00')
        ->and($window->instantAt(288)->format(DATE_RFC3339))->toBe('2026-03-29T00:00:00+02:00')
        ->and($window->instantAt(300)->format(DATE_RFC3339))->toBe('2026-03-29T01:00:00+02:00')
        ->and($window->instantAt(563)->format(DATE_RFC3339))->toBe('2026-03-29T23:55:00+03:00')
        ->and($window->instantAt(564)->format(DATE_RFC3339))->toBe('2026-03-30T00:00:00+03:00')
        ->and($window->instantAt(852)->format(DATE_RFC3339))->toBe('2026-03-31T00:00:00+03:00')
        ->and($window->instantAt(852)->getTimestamp())->toBe($window->days[2]->nextMidnight->getTimestamp())
        ->and($window->instantAt(300)->getTimezone()->getName())->toBe('Europe/Athens');
});

it('agrees with each day\'s own conversion at every index of the window', function () {
    $window = athensWindow();
    $checked = 0;

    foreach ($window->days as $day) {
        $offset = $window->offsetOf($day->day);

        for ($index = 0; $index < $day->width; $index++) {
            expect($window->instantAt($offset + $index)->getTimestamp())->toBe($day->instantAt($index)->getTimestamp())
                ->and($window->dayAt($offset + $index)->day)->toBe($day->day);
            $checked++;
        }
    }

    expect($checked)->toBe(852);
});

it('refuses an index below zero or past the width for an instant', function () {
    $window = athensWindow();

    expect(fn () => $window->instantAt(-1))->toThrow(InvalidArgumentException::class, 'An index on the window from 2026-03-28 to 2026-03-30 lies between 0 and 852, not -1.');
    expect(fn () => $window->instantAt(853))->toThrow(InvalidArgumentException::class, 'An index on the window from 2026-03-28 to 2026-03-30 lies between 0 and 852, not 853.');
});

it('refuses an empty window, however it is asked for', function () {
    expect(fn () => WindowGeometry::of([]))->toThrow(InvalidArgumentException::class, 'A window is at least one day long.');
    expect(fn () => athensWindow('2026-03-28', 0))->toThrow(InvalidArgumentException::class, 'A window is at least one day long.');
    expect(fn () => athensWindow('2026-03-28', -3))->toThrow(InvalidArgumentException::class, 'A window is at least one day long.');
});

it('refuses days in two zones or at two granularities', function () {
    expect(fn () => WindowGeometry::of([windowDay('2026-03-28'), windowDay('2026-03-29', 'UTC')]))
        ->toThrow(InvalidArgumentException::class, 'A window lies in one zone, not Europe/Athens and UTC.');
    expect(fn () => WindowGeometry::of([windowDay('2026-03-28'), windowDay('2026-03-29', 'Europe/Athens', 15)]))
        ->toThrow(InvalidArgumentException::class, 'A window has one granularity, not 5 and 15 minutes.');
});

it('refuses days that are not consecutive in date order: a gap, a reversal or a repeat', function () {
    expect(fn () => WindowGeometry::of([windowDay('2026-03-28'), windowDay('2026-03-30')]))
        ->toThrow(InvalidArgumentException::class, 'A window is consecutive days in date order, not 2026-03-28 followed by 2026-03-30.');
    expect(fn () => WindowGeometry::of([windowDay('2026-03-29'), windowDay('2026-03-28')]))
        ->toThrow(InvalidArgumentException::class, 'A window is consecutive days in date order, not 2026-03-29 followed by 2026-03-28.');
    expect(fn () => WindowGeometry::of([windowDay('2026-03-28'), windowDay('2026-03-28')]))
        ->toThrow(InvalidArgumentException::class, 'A window is consecutive days in date order, not 2026-03-28 followed by 2026-03-28.');
});

it('stays consecutive across a day whose local midnight the clocks skip', function () {
    $santiago = WindowGeometry::spanning('2024-09-07', 2, new DateTimeZone('America/Santiago'), Granularity::default());

    expect($santiago->width)->toBe(288 + 276)
        ->and($santiago->offsetOf('2024-09-08'))->toBe(288)
        ->and($santiago->instantAt(288)->format(DATE_RFC3339))->toBe('2024-09-08T01:00:00-03:00')
        ->and($santiago->instantAt(287)->format(DATE_RFC3339))->toBe('2024-09-07T23:55:00-04:00');
});

it('spans a sixty-day window at the default granularity and is 17280 slots wide when no clocks change', function () {
    $window = WindowGeometry::spanning('2026-05-01', 60, new DateTimeZone('Europe/Athens'), Granularity::default());

    expect($window->width)->toBe(17280)
        ->and($window->days)->toHaveCount(60)
        ->and($window->days[59]->day)->toBe('2026-06-29')
        ->and($window->offsetOf('2026-06-29'))->toBe(59 * 288)
        ->and($window->dayAt(17279)->day)->toBe('2026-06-29');
});
