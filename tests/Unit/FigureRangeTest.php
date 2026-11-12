<?php

use App\Application\Dto\FigureRange;

/*
 * The range Home's figures read: the whole calendar period a word names in the tenant's
 * zone, its days to come included, and the calendar period before it; a custom pair's period
 * before is the same number of days before it.
 */

/**
 * @return array{word: string, from: string, to: string, before: array{from: string, to: string}}
 */
function figureRangeRead(FigureRange $range): array
{
    $before = $range->before();

    return ['word' => $range->word, 'from' => $range->from, 'to' => $range->to, 'before' => ['from' => $before->from, 'to' => $before->to]];
}

/**
 * A window as UTC readings and the whole hours between them.
 *
 * @param  array{from: DateTimeImmutable, until: DateTimeImmutable}  $window
 * @return array{0: string, 1: string, 2: int}
 */
function figureWindowRead(array $window): array
{
    $utc = new DateTimeZone('UTC');

    return [$window['from']->setTimezone($utc)->format('Y-m-d H:i'), $window['until']->setTimezone($utc)->format('Y-m-d H:i'), intdiv($window['until']->getTimestamp() - $window['from']->getTimestamp(), 3600)];
}

it('reads each of Home\'s four words as its whole period and the period before: {0}', function (string $word, string $today, int $startOfWeek, array $read) {
    expect(figureRangeRead(FigureRange::named($word, $today, $startOfWeek)))->toBe($read);
})->with([
    'today' => ['today', '2026-10-21', 1, ['word' => 'today', 'from' => '2026-10-21', 'to' => '2026-10-21', 'before' => ['from' => '2026-10-20', 'to' => '2026-10-20']]],
    'yesterday across a month end' => ['yesterday', '2026-03-01', 1, ['word' => 'yesterday', 'from' => '2026-02-28', 'to' => '2026-02-28', 'before' => ['from' => '2026-02-27', 'to' => '2026-02-27']]],
    'yesterday across a year end' => ['yesterday', '2027-01-01', 1, ['word' => 'yesterday', 'from' => '2026-12-31', 'to' => '2026-12-31', 'before' => ['from' => '2026-12-30', 'to' => '2026-12-30']]],
    'today on a year\'s first day' => ['today', '2027-01-01', 1, ['word' => 'today', 'from' => '2027-01-01', 'to' => '2027-01-01', 'before' => ['from' => '2026-12-31', 'to' => '2026-12-31']]],
    'a week from Monday' => ['week', '2026-10-21', 1, ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25', 'before' => ['from' => '2026-10-12', 'to' => '2026-10-18']]],
    'a week from Sunday' => ['week', '2026-10-21', 7, ['word' => 'week', 'from' => '2026-10-18', 'to' => '2026-10-24', 'before' => ['from' => '2026-10-11', 'to' => '2026-10-17']]],
    'a week on its Sunday from Sunday' => ['week', '2026-10-25', 7, ['word' => 'week', 'from' => '2026-10-25', 'to' => '2026-10-31', 'before' => ['from' => '2026-10-18', 'to' => '2026-10-24']]],
    'a week across a month end' => ['week', '2026-10-30', 1, ['word' => 'week', 'from' => '2026-10-26', 'to' => '2026-11-01', 'before' => ['from' => '2026-10-19', 'to' => '2026-10-25']]],
    'a week across a year end' => ['week', '2026-12-31', 1, ['word' => 'week', 'from' => '2026-12-28', 'to' => '2027-01-03', 'before' => ['from' => '2026-12-21', 'to' => '2026-12-27']]],
    'a week across a year end from Sunday' => ['week', '2027-01-02', 7, ['word' => 'week', 'from' => '2026-12-27', 'to' => '2027-01-02', 'before' => ['from' => '2026-12-20', 'to' => '2026-12-26']]],
    'a month' => ['month', '2026-03-15', 1, ['word' => 'month', 'from' => '2026-03-01', 'to' => '2026-03-31', 'before' => ['from' => '2026-02-01', 'to' => '2026-02-28']]],
    'a month on its last day' => ['month', '2026-10-31', 1, ['word' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-31', 'before' => ['from' => '2026-09-01', 'to' => '2026-09-30']]],
    'a month across a year end' => ['month', '2027-01-31', 1, ['word' => 'month', 'from' => '2027-01-01', 'to' => '2027-01-31', 'before' => ['from' => '2026-12-01', 'to' => '2026-12-31']]],
    'a leap February' => ['month', '2028-03-01', 1, ['word' => 'month', 'from' => '2028-03-01', 'to' => '2028-03-31', 'before' => ['from' => '2028-02-01', 'to' => '2028-02-29']]],
]);

it('reads an unknown word, an empty one or none as this week', function (?string $word) {
    expect(figureRangeRead(FigureRange::named($word, '2026-10-21', 1)))
        ->toBe(['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25', 'before' => ['from' => '2026-10-12', 'to' => '2026-10-18']]);
})->with([
    'an unknown word' => ['fortnight'],
    'a word in capitals' => ['MONTH'],
    'the custom word' => ['custom'],
    'an empty word' => [''],
    'none' => [null],
]);

it('steps a period before twice back as the calendar does: a month before a month before', function () {
    $twice = FigureRange::named('month', '2026-03-31', 1)->before()->before();

    expect([$twice->from, $twice->to])->toBe(['2026-01-01', '2026-01-31']);
});

it('reads a custom pair as its days, both inclusive, and its period before as as many days before it', function () {
    $range = FigureRange::custom('2026-10-05', '2026-10-14') ?? throw new LogicException;

    expect(figureRangeRead($range))->toBe(['word' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-14', 'before' => ['from' => '2026-09-25', 'to' => '2026-10-04']])
        ->and($range->days())->toBe(10);

    $acrossTheYear = FigureRange::custom('2027-01-01', '2027-01-03');
    expect(figureRangeRead($acrossTheYear ?? throw new LogicException))->toBe(['word' => 'custom', 'from' => '2027-01-01', 'to' => '2027-01-03', 'before' => ['from' => '2026-12-29', 'to' => '2026-12-31']]);

    $oneDay = FigureRange::custom('2026-10-05', '2026-10-05');
    expect(figureRangeRead($oneDay ?? throw new LogicException)['before'])->toBe(['from' => '2026-10-04', 'to' => '2026-10-04']);
});

it('refuses a custom pair that is not two real dates in order', function (?string $from, ?string $to) {
    expect(FigureRange::custom($from, $to))->toBeNull();
})->with([
    'the To before the From' => ['2026-10-14', '2026-10-05'],
    'a date that is none' => ['2026-02-30', '2026-03-02'],
    'a word' => ['today', '2026-10-05'],
    'none' => [null, '2026-10-05'],
]);

it('counts its days: one for a day, seven for a week, the month\'s own for a month', function () {
    expect(FigureRange::named('today', '2026-10-21', 1)->days())->toBe(1)
        ->and(FigureRange::named('week', '2026-10-21', 1)->days())->toBe(7)
        ->and(FigureRange::named('month', '2026-02-10', 1)->days())->toBe(28)
        ->and(FigureRange::named('month', '2026-02-10', 1)->before()->days())->toBe(31);
});

it('reads as instants from the first day\'s midnight in the zone to the midnight after the last, across both clock changes in Athens', function () {
    $athens = new DateTimeZone('Europe/Athens');
    $spelled = figureWindowRead(...);

    // The clocks go back on Sunday the 25th of October 2026: the week holds 169 hours.
    expect($spelled(FigureRange::named('week', '2026-10-21', 1)->window($athens)))->toBe(['2026-10-18 21:00', '2026-10-25 22:00', 169])
        // and forward on Sunday the 29th of March 2026: that day holds 23.
        ->and($spelled(FigureRange::named('today', '2026-03-29', 1)->window($athens)))->toBe(['2026-03-28 22:00', '2026-03-29 21:00', 23])
        ->and($spelled(FigureRange::named('month', '2026-10-02', 1)->window($athens)))->toBe(['2026-09-30 21:00', '2026-10-31 22:00', 745])
        ->and($spelled(FigureRange::named('week', '2026-10-21', 1)->before()->window($athens)))->toBe(['2026-10-11 21:00', '2026-10-18 21:00', 168]);
});

it('answers the range and the period before as the prop names them, no instant in either', function () {
    $range = FigureRange::named('week', '2026-10-21', 1);

    expect($range->toArray())->toBe(['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25'])
        ->and($range->before()->toArray())->toBe(['word' => 'week', 'from' => '2026-10-12', 'to' => '2026-10-18']);
});

/*
 * Reports' words: the four Home names and four more, each the whole period it names with its
 * days to come, and its period before; Home keeps its four.
 */

it('reads each of Reports\' further words as its period and the period before: {0}', function (string $word, string $today, array $read) {
    expect(figureRangeRead(FigureRange::named($word, $today, 1, FigureRange::REPORTS)))->toBe($read);
})->with([
    'the last 7 days, today the last' => ['last_7_days', '2026-10-21', ['word' => 'last_7_days', 'from' => '2026-10-15', 'to' => '2026-10-21', 'before' => ['from' => '2026-10-08', 'to' => '2026-10-14']]],
    'the last 7 days across a month end' => ['last_7_days', '2026-11-02', ['word' => 'last_7_days', 'from' => '2026-10-27', 'to' => '2026-11-02', 'before' => ['from' => '2026-10-20', 'to' => '2026-10-26']]],
    'the last 7 days across a year end' => ['last_7_days', '2027-01-03', ['word' => 'last_7_days', 'from' => '2026-12-28', 'to' => '2027-01-03', 'before' => ['from' => '2026-12-21', 'to' => '2026-12-27']]],
    'the last 30 days' => ['last_30_days', '2026-10-21', ['word' => 'last_30_days', 'from' => '2026-09-22', 'to' => '2026-10-21', 'before' => ['from' => '2026-08-23', 'to' => '2026-09-21']]],
    'the last 30 days over a leap day' => ['last_30_days', '2028-03-10', ['word' => 'last_30_days', 'from' => '2028-02-10', 'to' => '2028-03-10', 'before' => ['from' => '2028-01-11', 'to' => '2028-02-09']]],
    'last month' => ['last_month', '2026-10-21', ['word' => 'last_month', 'from' => '2026-09-01', 'to' => '2026-09-30', 'before' => ['from' => '2026-08-01', 'to' => '2026-08-31']]],
    'last month on a month\'s last day' => ['last_month', '2026-03-31', ['word' => 'last_month', 'from' => '2026-02-01', 'to' => '2026-02-28', 'before' => ['from' => '2026-01-01', 'to' => '2026-01-31']]],
    'last month across a year end' => ['last_month', '2027-01-15', ['word' => 'last_month', 'from' => '2026-12-01', 'to' => '2026-12-31', 'before' => ['from' => '2026-11-01', 'to' => '2026-11-30']]],
    'last month a leap February' => ['last_month', '2028-03-01', ['word' => 'last_month', 'from' => '2028-02-01', 'to' => '2028-02-29', 'before' => ['from' => '2028-01-01', 'to' => '2028-01-31']]],
    'this year' => ['year', '2026-10-21', ['word' => 'year', 'from' => '2026-01-01', 'to' => '2026-12-31', 'before' => ['from' => '2025-01-01', 'to' => '2025-12-31']]],
    'this year on its first day' => ['year', '2027-01-01', ['word' => 'year', 'from' => '2027-01-01', 'to' => '2027-12-31', 'before' => ['from' => '2026-01-01', 'to' => '2026-12-31']]],
    'a leap year' => ['year', '2028-06-01', ['word' => 'year', 'from' => '2028-01-01', 'to' => '2028-12-31', 'before' => ['from' => '2027-01-01', 'to' => '2027-12-31']]],
    'Home\'s week among them' => ['week', '2026-10-21', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25', 'before' => ['from' => '2026-10-12', 'to' => '2026-10-18']]],
    'an unknown word as this week' => ['decade', '2026-10-21', ['word' => 'week', 'from' => '2026-10-19', 'to' => '2026-10-25', 'before' => ['from' => '2026-10-12', 'to' => '2026-10-18']]],
]);

it('keeps Home to its four words: a word of Reports alone reads as this week there', function (string $word) {
    expect(FigureRange::named($word, '2026-10-21', 1)->word)->toBe('week');
})->with(['last_7_days', 'last_30_days', 'last_month', 'year']);

it('lists Reports\' words in the order its Range lists them, the custom range apart', function () {
    expect(FigureRange::REPORTS)->toBe(['today', 'yesterday', 'week', 'last_7_days', 'last_30_days', 'month', 'last_month', 'year']);
});

it('counts the days of the further words, a leap year\'s 366', function () {
    expect(FigureRange::named('last_7_days', '2026-10-21', 1, FigureRange::REPORTS)->days())->toBe(7)
        ->and(FigureRange::named('last_30_days', '2026-10-21', 1, FigureRange::REPORTS)->days())->toBe(30)
        ->and(FigureRange::named('year', '2028-10-21', 1, FigureRange::REPORTS)->days())->toBe(366)
        ->and(FigureRange::named('year', '2028-10-21', 1, FigureRange::REPORTS)->before()->days())->toBe(365);
});

it('reads the last 7 days across the clocks going back in Athens as 169 hours, and this year as its whole', function () {
    $athens = new DateTimeZone('Europe/Athens');

    expect(figureWindowRead(FigureRange::named('last_7_days', '2026-10-25', 1, FigureRange::REPORTS)->window($athens)))->toBe(['2026-10-18 21:00', '2026-10-25 22:00', 169])
        ->and(figureWindowRead(FigureRange::named('last_7_days', '2026-03-29', 1, FigureRange::REPORTS)->window($athens)))->toBe(['2026-03-22 22:00', '2026-03-29 21:00', 167])
        ->and(figureWindowRead(FigureRange::named('year', '2026-10-21', 1, FigureRange::REPORTS)->window($athens)))->toBe(['2025-12-31 22:00', '2026-12-31 22:00', 8760]);
});

it('takes a custom range of up to 366 days and refuses a longer one', function () {
    expect(FigureRange::custom('2028-01-01', '2028-12-31')?->days())->toBe(366)
        ->and(FigureRange::custom('2026-01-01', '2027-01-01')?->days())->toBe(366)
        ->and(FigureRange::custom('2026-01-01', '2027-01-02'))->toBeNull()
        ->and(FigureRange::MOST_DAYS)->toBe(366);
});

/**
 * A range's buckets as from-to pairs joined, after their unit.
 *
 * @return array{0: string, 1: list<string>}
 */
function figureBucketsRead(FigureRange $range, int $startOfWeek): array
{
    $buckets = $range->buckets($startOfWeek);

    return [$buckets['unit'], array_map(fn (array $row): string => $row['from'].'/'.$row['to'], $buckets['rows'])];
}

it('cuts a range of up to 31 days into one bucket per day', function () {
    $range = FigureRange::custom('2026-10-01', '2026-10-31');
    assert($range instanceof FigureRange);
    [$unit, $rows] = figureBucketsRead($range, 1);

    expect($unit)->toBe('day')
        ->and($rows)->toHaveCount(31)
        ->and($rows[0])->toBe('2026-10-01/2026-10-01')
        ->and($rows[30])->toBe('2026-10-31/2026-10-31')
        ->and(figureBucketsRead(FigureRange::named('today', '2026-10-21', 1, FigureRange::REPORTS), 1))->toBe(['day', ['2026-10-21/2026-10-21']]);
});

it('cuts a range of 32 to 183 days into weeks of the tenant\'s week start, the first and the last cut to the range', function () {
    // Thursday 1 October to Sunday 1 November, 32 days: Monday weeks.
    $monday = FigureRange::custom('2026-10-01', '2026-11-01');
    assert($monday instanceof FigureRange);

    expect(figureBucketsRead($monday, 1))->toBe(['week', [
        '2026-10-01/2026-10-04', '2026-10-05/2026-10-11', '2026-10-12/2026-10-18', '2026-10-19/2026-10-25', '2026-10-26/2026-11-01',
    ]])
        // The same range on Sunday weeks.
        ->and(figureBucketsRead($monday, 7))->toBe(['week', [
            '2026-10-01/2026-10-03', '2026-10-04/2026-10-10', '2026-10-11/2026-10-17', '2026-10-18/2026-10-24', '2026-10-25/2026-10-31', '2026-11-01/2026-11-01',
        ]]);

    $longest = FigureRange::custom('2026-01-01', '2026-07-02');
    assert($longest instanceof FigureRange);
    [$unit, $rows] = figureBucketsRead($longest, 1);

    // 183 days, still weeks: Thursday 1 January to Thursday 2 July.
    expect($unit)->toBe('week')
        ->and($rows[0])->toBe('2026-01-01/2026-01-04')
        ->and($rows[count($rows) - 1])->toBe('2026-06-29/2026-07-02');
});

it('cuts a range longer than 183 days into calendar months, the first and the last cut to the range', function () {
    $range = FigureRange::custom('2025-12-31', '2026-07-02');
    assert($range instanceof FigureRange);

    // 184 days.
    expect(figureBucketsRead($range, 1))->toBe(['month', [
        '2025-12-31/2025-12-31', '2026-01-01/2026-01-31', '2026-02-01/2026-02-28', '2026-03-01/2026-03-31', '2026-04-01/2026-04-30', '2026-05-01/2026-05-31', '2026-06-01/2026-06-30', '2026-07-01/2026-07-02',
    ]]);

    [$unit, $rows] = figureBucketsRead(FigureRange::named('year', '2026-10-21', 1, FigureRange::REPORTS), 1);

    expect($unit)->toBe('month')
        ->and($rows)->toHaveCount(12)
        ->and($rows[11])->toBe('2026-12-01/2026-12-31');
});
