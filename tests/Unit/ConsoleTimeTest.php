<?php

use App\Platform\ConsoleTime;

/*
 * The console's one clock: an instant spelled in the console's configured zone, in the house's
 * spelled-out form, with the zone named for a message and without it for a screen, whose top bar
 * names it once. The zone is handed in here as configuration hands it in, so the suite boots
 * nothing; Europe/Athens is the default, and its two clock changes of 2026 are where a spelling
 * that converted by a fixed offset would go wrong.
 */

function consoleInstant(string $utc): DateTimeImmutable
{
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
}

it('spells an instant in the console\'s zone with the zone named, whatever zone the instant came in', function () {
    $time = new ConsoleTime('Europe/Athens');

    expect($time->stamp(consoleInstant('2026-10-14 09:00')))->toBe('Wednesday 14 October 2026 at 12:00 (Europe/Athens)')
        ->and($time->stamp(new DateTimeImmutable('2026-10-14 05:00', new DateTimeZone('America/New_York'))))->toBe('Wednesday 14 October 2026 at 12:00 (Europe/Athens)')
        ->and($time->zone()->getName())->toBe('Europe/Athens');
});

it('holds across the autumn clock change: {case}', function (string $utc, string $spelled) {
    expect((new ConsoleTime('Europe/Athens'))->stamp(consoleInstant($utc)))->toBe($spelled);
})->with([
    'the day before, at summer time' => ['2026-10-24 09:00', 'Saturday 24 October 2026 at 12:00 (Europe/Athens)'],
    'the last minute of summer time' => ['2026-10-25 00:59', 'Sunday 25 October 2026 at 03:59 (Europe/Athens)'],
    'the first minute of winter time, the hour repeated' => ['2026-10-25 01:00', 'Sunday 25 October 2026 at 03:00 (Europe/Athens)'],
    'the day after, at winter time' => ['2026-10-26 09:00', 'Monday 26 October 2026 at 11:00 (Europe/Athens)'],
]);

it('holds across the spring clock change: {case}', function (string $utc, string $spelled) {
    expect((new ConsoleTime('Europe/Athens'))->stamp(consoleInstant($utc)))->toBe($spelled);
})->with([
    'the last minute of winter time' => ['2026-03-29 00:59', 'Sunday 29 March 2026 at 02:59 (Europe/Athens)'],
    'the first minute of summer time, the hour skipped' => ['2026-03-29 01:00', 'Sunday 29 March 2026 at 04:00 (Europe/Athens)'],
]);

it('spells the instant in another zone where the configuration names another', function () {
    $time = new ConsoleTime('UTC');

    expect($time->stamp(consoleInstant('2026-10-25 01:00')))->toBe('Sunday 25 October 2026 at 01:00 (UTC)')
        ->and((new ConsoleTime('America/New_York'))->stamp(consoleInstant('2026-10-25 01:00')))->toBe('Saturday 24 October 2026 at 21:00 (America/New_York)');
});

it('refuses a zone the configuration misspells, rather than falling back to another clock', function () {
    new ConsoleTime('Europe/Atlantis');
})->throws(DateInvalidTimeZoneException::class);

it('spells an instant for a console screen in the console\'s zone without naming it, the top bar naming the zone once', function () {
    $time = new ConsoleTime('Europe/Athens');

    expect($time->onScreen(consoleInstant('2026-10-14 09:00')))->toBe('Wednesday 14 October 2026 at 12:00')
        ->and($time->onScreen(consoleInstant('2026-10-25 01:00')))->toBe('Sunday 25 October 2026 at 03:00')
        ->and((new ConsoleTime('UTC'))->onScreen(consoleInstant('2026-10-25 01:00')))->toBe('Sunday 25 October 2026 at 01:00');
});
