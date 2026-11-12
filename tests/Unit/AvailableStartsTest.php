<?php

use App\Application\Dto\AvailableStarts;

// Every instant AvailableStarts holds is where a whole chain starts, never a later segment's
// start, so every() judges a chain by its first segment's start alone by construction; the
// later segments follow the chain's geometry wherever that start lands. No test here says so
// because no answer could say otherwise.

function everyInstant(string $text, string $zone = 'Europe/Athens'): DateTimeImmutable
{
    return new DateTimeImmutable($text, new DateTimeZone($zone));
}

/**
 * Every 5 minutes of elapsed time from the first instant to the last, both included, each
 * carried in the tenant's zone as the use case hands them over. Counted in UTC, so a run
 * spelled in UTC walks straight through a clock change and its repeated hour is unambiguous.
 *
 * @return list<DateTimeImmutable>
 */
function everyFiveMinutes(string $first, string $last, string $zone = 'Europe/Athens', string $spelledIn = 'Europe/Athens'): array
{
    $utc = new DateTimeZone('UTC');
    $start = everyInstant($first, $spelledIn)->setTimezone($utc);
    $end = everyInstant($last, $spelledIn);
    $starts = [];

    while ($start->getTimestamp() <= $end->getTimestamp()) {
        $starts[] = $start->setTimezone(new DateTimeZone($zone));
        $start = $start->add(new DateInterval('PT5M'));
    }

    return $starts;
}

/**
 * @return array<string, list<string>>
 */
function everyReadings(AvailableStarts $starts, string $format = 'H:i', string $zone = 'Europe/Athens'): array
{
    return array_map(fn (array $day): array => array_map(fn (DateTimeImmutable $start): string => $start->setTimezone(new DateTimeZone($zone))->format($format), $day), $starts->byDay);
}

it('keeps a start every {minutes} minutes of the hour out of a day of 5-minute starts', function (int $minutes, array $expected) {
    $starts = new AvailableStarts(['2026-04-06' => everyFiveMinutes('2026-04-06 09:00', '2026-04-06 10:55')]);

    expect(everyReadings($starts->every($minutes, new DateTimeZone('Europe/Athens'))))->toBe(['2026-04-06' => $expected]);
})->with([
    'five, every one of them' => [5, [
        '09:00', '09:05', '09:10', '09:15', '09:20', '09:25', '09:30', '09:35', '09:40', '09:45', '09:50', '09:55',
        '10:00', '10:05', '10:10', '10:15', '10:20', '10:25', '10:30', '10:35', '10:40', '10:45', '10:50', '10:55',
    ]],
    'fifteen, the quarter hours' => [15, ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45']],
    'twenty' => [20, ['09:00', '09:20', '09:40', '10:00', '10:20', '10:40']],
    'sixty, minute zero alone' => [60, ['09:00', '10:00']],
]);

it('offers 09:15 first when the window opens at 09:10, off the interval', function () {
    $starts = new AvailableStarts(['2026-04-06' => everyFiveMinutes('2026-04-06 09:10', '2026-04-06 10:00')]);

    expect(everyReadings($starts->every(15, new DateTimeZone('Europe/Athens'))))->toBe(['2026-04-06' => ['09:15', '09:30', '09:45', '10:00']]);
});

it('keeps the wall clock aligned across the spring change, 03:00 to 04:00 in Athens on 2026-03-29', function () {
    $starts = new AvailableStarts(['2026-03-29' => everyFiveMinutes('2026-03-29 00:30', '2026-03-29 01:30', spelledIn: 'UTC')]);

    expect(everyReadings($starts, 'H:iP')['2026-03-29'])->toContain('02:55+02:00', '04:00+03:00')->not->toContain('03:00+03:00')
        ->and(everyReadings($starts->every(15, new DateTimeZone('Europe/Athens')), 'H:iP'))->toBe([
            '2026-03-29' => ['02:30+02:00', '02:45+02:00', '04:00+03:00', '04:15+03:00', '04:30+03:00'],
        ]);
});

it('keeps both readings of 03:15 across the autumn change, 04:00 back to 03:00 in Athens on 2026-10-25, and drops both readings of 03:05', function () {
    $starts = new AvailableStarts(['2026-10-25' => everyFiveMinutes('2026-10-25 00:00', '2026-10-25 01:55', spelledIn: 'UTC')]);

    $kept = $starts->every(15, new DateTimeZone('Europe/Athens'));
    $readings = everyReadings($kept, 'H:iP')['2026-10-25'];

    expect($readings)->toBe([
        '03:00+03:00', '03:15+03:00', '03:30+03:00', '03:45+03:00',
        '03:00+02:00', '03:15+02:00', '03:30+02:00', '03:45+02:00',
    ])
        ->and($kept->byDay['2026-10-25'][5]->getTimestamp() - $kept->byDay['2026-10-25'][1]->getTimestamp())->toBe(3600)
        ->and(everyReadings($starts, 'H:iP')['2026-10-25'])->toContain('03:05+03:00', '03:05+02:00')
        ->and($readings)->not->toContain('03:05+03:00')
        ->and($readings)->not->toContain('03:05+02:00');
});

it('reads the wall clock and not the minutes elapsed since midnight, on Lord Howe\'s half-hour spring change of 2026-10-04', function () {
    // 02:00+10:30 becomes 02:30+11:00, so minute zero on the wall clock moves from minute 30 UTC
    // to minute 0, and the elapsed count from local midnight lands on the half hours after it.
    $starts = new AvailableStarts(['2026-10-04' => everyFiveMinutes('2026-10-03 14:00', '2026-10-03 16:55', 'Australia/Lord_Howe', 'UTC')]);

    expect(everyReadings($starts->every(60, new DateTimeZone('Australia/Lord_Howe')), DATE_RFC3339, 'Australia/Lord_Howe'))->toBe([
        '2026-10-04' => ['2026-10-04T01:00:00+10:30', '2026-10-04T03:00:00+11:00'],
    ]);
});

it('keeps every day key in its order, a day left with nothing as an empty list, and the kept starts in their order', function () {
    $starts = new AvailableStarts([
        '2026-04-08' => [everyInstant('2026-04-08 09:05'), everyInstant('2026-04-08 09:10')],
        '2026-04-06' => [],
        '2026-04-07' => [everyInstant('2026-04-07 09:00'), everyInstant('2026-04-07 09:05'), everyInstant('2026-04-07 09:15'), everyInstant('2026-04-07 16:45')],
    ]);

    expect(everyReadings($starts->every(15, new DateTimeZone('Europe/Athens'))))->toBe([
        '2026-04-08' => [],
        '2026-04-06' => [],
        '2026-04-07' => ['09:00', '09:15', '16:45'],
    ]);
});

it('reads the minute in the zone it is given, not the zone the instant carries: minute 30 UTC is minute zero in Kolkata', function () {
    $starts = new AvailableStarts(['2026-10-01' => everyFiveMinutes('2026-10-01 03:00', '2026-10-01 04:55', 'UTC', 'UTC')]);

    expect(everyReadings($starts->every(60, new DateTimeZone('UTC')), 'H:i', 'UTC'))->toBe(['2026-10-01' => ['03:00', '04:00']])
        ->and(everyReadings($starts->every(60, new DateTimeZone('Asia/Kolkata')), 'H:i', 'UTC'))->toBe(['2026-10-01' => ['03:30', '04:30']])
        ->and(everyReadings($starts->every(60, new DateTimeZone('Asia/Kolkata')), 'H:i', 'Asia/Kolkata'))->toBe(['2026-10-01' => ['09:00', '10:00']]);
});

it('refuses an interval of {minutes}', function (int $minutes) {
    $starts = new AvailableStarts(['2026-04-06' => everyFiveMinutes('2026-04-06 09:00', '2026-04-06 10:00')]);

    expect(fn () => $starts->every($minutes, new DateTimeZone('Europe/Athens')))
        ->toThrow(InvalidArgumentException::class, sprintf('A start interval is a number of minutes dividing sixty, not %d.', $minutes));
})->with([
    'zero' => [0],
    'minus fifteen, which PHP counts as dividing sixty' => [-15],
    'seven' => [7],
    'forty-five, which leaves a quarter of the hour over' => [45],
]);

it('names the first day holding a start, in the order asked for, and none for an answer whose every day is empty', function () {
    $starts = new AvailableStarts([
        '2026-04-06' => [],
        '2026-04-07' => [everyInstant('2026-04-07 09:00')],
        '2026-04-08' => [everyInstant('2026-04-08 09:00')],
    ]);

    expect($starts->firstDayWithAStart())->toBe('2026-04-07')
        ->and(AvailableStarts::none(['2026-04-06', '2026-04-07'])->firstDayWithAStart())->toBeNull();
});
