<?php

use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\StaffBlock;

/*
 * Step 1 of the write protocol, with no database: the days a block range touches are the
 * tenant's local days, the set is sorted by staff member then day, and no pair appears twice.
 * The tenant zone is Europe/Athens unless a test says otherwise: UTC+2 in winter, UTC+3 in
 * summer, so local midnight is 22:00 or 21:00 UTC of the day before.
 */

function lockInstant(string $utc): DateTimeImmutable
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
}

/**
 * @return list<string>
 */
function athensKeys(StaffBlock ...$blocks): array
{
    return array_map(
        fn (LockKey $key): string => $key->toString(),
        LockKeySet::forBlocks($blocks, new DateTimeZone('Europe/Athens'))->all(),
    );
}

it('pairs a range inside one local day with that day', function () {
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-10-01 07:00'), lockInstant('2026-10-01 08:00'))))
        ->toBe(['1:2026-10-01']);
});

it('yields two days for a range crossing local midnight', function () {
    // 23:30 to 00:30 in Athens, both instants on the first of October in UTC.
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-10-01 20:30'), lockInstant('2026-10-01 21:30'))))
        ->toBe(['1:2026-10-01', '1:2026-10-02']);
});

it('yields one day for a range ending exactly at local midnight, because the range is half-open', function () {
    // 23:00 to 00:00 in Athens.
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-10-01 20:00'), lockInstant('2026-10-01 21:00'))))
        ->toBe(['1:2026-10-01']);
});

it('yields the new day for a range starting exactly at local midnight', function () {
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-10-01 21:00'), lockInstant('2026-10-01 22:00'))))
        ->toBe(['1:2026-10-02']);
});

it('takes the days from the tenant zone and not from UTC', function () {
    // One UTC day, two Athens days: 23:00 on the 28th of March to 01:00 on the 29th.
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-03-28 21:00'), lockInstant('2026-03-28 23:00'))))
        ->toBe(['1:2026-03-28', '1:2026-03-29']);

    // The first of October in UTC is already the second in Athens.
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-10-01 21:30'), lockInstant('2026-10-01 22:30'))))
        ->toBe(['1:2026-10-02']);

    // A UTC tenant sees UTC days.
    $utcKeys = LockKeySet::forBlocks(
        [new StaffBlock(1, lockInstant('2026-10-01 21:30'), lockInstant('2026-10-01 22:30'))],
        new DateTimeZone('UTC'),
    )->all();

    expect($utcKeys[0]->toString())->toBe('1:2026-10-01');
});

it('treats the 23-hour day of the spring transition as one day', function () {
    // Athens goes from 03:00 to 04:00 on the 29th of March 2026. Local midnight is 22:00 UTC
    // the night before and 21:00 UTC the night after: the whole local day is 23 hours.
    $midnightBefore = lockInstant('2026-03-28 22:00');
    $midnightAfter = lockInstant('2026-03-29 21:00');

    expect(athensKeys(new StaffBlock(1, $midnightBefore, $midnightAfter)))->toBe(['1:2026-03-29'])
        ->and(athensKeys(new StaffBlock(1, $midnightBefore, $midnightAfter->modify('+1 second'))))->toBe(['1:2026-03-29', '1:2026-03-30'])
        ->and(athensKeys(new StaffBlock(1, lockInstant('2026-03-29 00:30'), lockInstant('2026-03-29 01:30'))))->toBe(['1:2026-03-29']);
});

it('treats the 25-hour day of the autumn transition as one day', function () {
    // Athens goes from 04:00 back to 03:00 on the 25th of October 2026. Local midnight is 21:00
    // UTC the night before and 22:00 UTC the night after: the whole local day is 25 hours.
    $midnightBefore = lockInstant('2026-10-24 21:00');
    $midnightAfter = lockInstant('2026-10-25 22:00');

    expect(athensKeys(new StaffBlock(1, $midnightBefore, $midnightAfter)))->toBe(['1:2026-10-25'])
        ->and(athensKeys(new StaffBlock(1, $midnightBefore, $midnightBefore->modify('+24 hours'))))->toBe(['1:2026-10-25'])
        ->and(athensKeys(new StaffBlock(1, $midnightBefore, $midnightAfter->modify('+1 second'))))->toBe(['1:2026-10-25', '1:2026-10-26'])
        ->and(athensKeys(new StaffBlock(1, lockInstant('2026-10-25 00:30'), lockInstant('2026-10-25 01:30'))))->toBe(['1:2026-10-25']);
});

it('handles a zone whose transition removes midnight itself', function () {
    // Santiago goes from 00:00 to 01:00 on the 6th of September 2026: the day's first instant
    // is 01:00 -03, which is 04:00 UTC. Ending exactly there touches the day before only.
    $zone = new DateTimeZone('America/Santiago');
    $days = fn (string $start, string $end): array => array_map(
        fn (LockKey $key): string => $key->day,
        LockKeySet::forBlocks([new StaffBlock(1, lockInstant($start), lockInstant($end))], $zone)->all(),
    );

    expect($days('2026-09-06 03:00', '2026-09-06 04:00'))->toBe(['2026-09-05'])
        ->and($days('2026-09-06 03:00', '2026-09-06 04:00:01'))->toBe(['2026-09-05', '2026-09-06'])
        // The day after the transition starts at 00:00 -03, which is 03:00 UTC: a range ending
        // at 03:30 UTC on the 7th touches the 7th. Adding a day to the 6th's first instant
        // would have put that midnight at 04:00 UTC and missed it.
        ->and($days('2026-09-06 12:00', '2026-09-07 03:30'))->toBe(['2026-09-06', '2026-09-07']);
});

it('yields every day of a range spanning several', function () {
    expect(athensKeys(new StaffBlock(1, lockInstant('2026-10-01 10:00'), lockInstant('2026-10-03 10:00'))))
        ->toBe(['1:2026-10-01', '1:2026-10-02', '1:2026-10-03']);
});

it('sorts ascending by staff member then day whatever order the items came in', function () {
    expect(athensKeys(
        new StaffBlock(9, lockInstant('2026-10-02 08:00'), lockInstant('2026-10-02 09:00')),
        new StaffBlock(2, lockInstant('2026-10-05 08:00'), lockInstant('2026-10-05 09:00')),
        new StaffBlock(9, lockInstant('2026-10-01 08:00'), lockInstant('2026-10-01 09:00')),
        new StaffBlock(2, lockInstant('2026-10-03 08:00'), lockInstant('2026-10-03 09:00')),
    ))->toBe(['2:2026-10-03', '2:2026-10-05', '9:2026-10-01', '9:2026-10-02']);
});

it('yields one key for two items on the same staff-day', function () {
    expect(athensKeys(
        new StaffBlock(1, lockInstant('2026-10-01 07:00'), lockInstant('2026-10-01 08:00')),
        new StaffBlock(1, lockInstant('2026-10-01 09:00'), lockInstant('2026-10-01 10:00')),
    ))->toBe(['1:2026-10-01']);
});

it('yields one key per staff member for two items on the same day with different staff', function () {
    expect(athensKeys(
        new StaffBlock(4, lockInstant('2026-10-01 07:00'), lockInstant('2026-10-01 08:00')),
        new StaffBlock(3, lockInstant('2026-10-01 07:00'), lockInstant('2026-10-01 08:00')),
    ))->toBe(['3:2026-10-01', '4:2026-10-01']);
});

it('reads only the instant of each bound, whatever zone it was written in', function () {
    $newYork = new DateTimeZone('America/New_York');
    $inNewYork = new StaffBlock(1, new DateTimeImmutable('2026-10-01 16:30', $newYork), new DateTimeImmutable('2026-10-01 17:30', $newYork));
    $inUtc = new StaffBlock(1, lockInstant('2026-10-01 20:30'), lockInstant('2026-10-01 21:30'));

    expect(athensKeys($inNewYork))->toBe(athensKeys($inUtc))->toBe(['1:2026-10-01', '1:2026-10-02']);
});

it('sorts and de-duplicates keys given directly, so no caller can hold an unsorted set', function () {
    $set = LockKeySet::of(
        new LockKey(9, '2026-10-01'),
        new LockKey(2, '2026-10-05'),
        new LockKey(9, '2026-10-01'),
        new LockKey(2, '2026-10-03'),
    );

    expect(array_map(fn (LockKey $key): string => $key->toString(), $set->all()))
        ->toBe(['2:2026-10-03', '2:2026-10-05', '9:2026-10-01'])
        ->and(count($set))->toBe(3)
        ->and(array_map(fn (LockKey $key): string => $key->toString(), iterator_to_array($set)))
        ->toBe(['2:2026-10-03', '2:2026-10-05', '9:2026-10-01']);
});

it('refuses to be empty', function () {
    expect(fn () => LockKeySet::of())->toThrow(InvalidArgumentException::class)
        ->and(fn () => LockKeySet::forBlocks([], new DateTimeZone('UTC')))->toThrow(InvalidArgumentException::class);
});

it('refuses a block range that does not end after it starts', function () {
    $instant = lockInstant('2026-10-01 10:00');

    expect(fn () => new StaffBlock(1, $instant, $instant))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StaffBlock(1, $instant, $instant->modify('-1 second')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new StaffBlock(0, $instant, $instant->modify('+1 hour')))->toThrow(InvalidArgumentException::class);
});

it('equals a set naming the same staff-days however each was built, and differs from one naming more, fewer or other days', function () {
    $day = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(2, '2026-10-02'));
    $again = LockKeySet::of(new LockKey(2, '2026-10-02'), new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-01'));
    $more = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(2, '2026-10-02'), new LockKey(2, '2026-10-03'));
    $fewer = LockKeySet::of(new LockKey(1, '2026-10-01'));
    $other = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(3, '2026-10-02'));

    expect($day->equals($again))->toBeTrue()
        ->and($again->equals($day))->toBeTrue()
        ->and($day->equals($more))->toBeFalse()
        ->and($more->equals($day))->toBeFalse()
        ->and($day->equals($fewer))->toBeFalse()
        ->and($day->equals($other))->toBeFalse();
});

it('unions two sets into one sorted, de-duplicated set naming every staff-day either names', function () {
    $leaving = LockKeySet::of(new LockKey(2, '2026-10-05'), new LockKey(1, '2026-10-01'));
    $taking = LockKeySet::of(new LockKey(1, '2026-10-03'), new LockKey(2, '2026-10-05'));

    expect(array_map(fn (LockKey $key): string => $key->toString(), $leaving->union($taking)->all()))
        ->toBe(['1:2026-10-01', '1:2026-10-03', '2:2026-10-05'])
        // The union is the same set whichever way round it is taken, which is what keeps the
        // lock order the same for two movers crossing over one another.
        ->and(array_map(fn (LockKey $key): string => $key->toString(), $taking->union($leaving)->all()))
        ->toBe(['1:2026-10-01', '1:2026-10-03', '2:2026-10-05']);
});

it('covers a subset, itself and nothing that names a staff-day it does not hold', function () {
    $union = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-03'), new LockKey(2, '2026-10-05'));

    expect($union->covers($union))->toBeTrue()
        ->and($union->covers(LockKeySet::of(new LockKey(1, '2026-10-03'))))->toBeTrue()
        ->and($union->covers(LockKeySet::of(new LockKey(2, '2026-10-05'), new LockKey(1, '2026-10-01'))))->toBeTrue()
        // One pair outside it is enough, whether it sorts before, between or after the rest.
        ->and($union->covers(LockKeySet::of(new LockKey(1, '2026-09-30'))))->toBeFalse()
        ->and($union->covers(LockKeySet::of(new LockKey(1, '2026-10-02'))))->toBeFalse()
        ->and($union->covers(LockKeySet::of(new LockKey(3, '2026-10-05'))))->toBeFalse()
        ->and($union->covers(LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(2, '2026-10-06'))))->toBeFalse()
        ->and(LockKeySet::of(new LockKey(1, '2026-10-01'))->covers($union))->toBeFalse();
});
