<?php

use App\Application\Notification\ReminderTime;

/*
 * When a reminder goes: the lead before the start, as elapsed time between two instants and
 * never as a wall-clock unit. A clock change lies between now and the start for part of every
 * year, and "a day before" on the tenant's wall clock is 23 or 25 hours of elapsed time on
 * those days; the queue counts elapsed seconds, so the delay has to be the same kind of number.
 *
 * Europe/Athens leaves summer time on Sunday 25 October 2026 at 04:00, which becomes 03:00, and
 * enters it on Sunday 29 March 2026 at 03:00, which becomes 04:00. Both are asserted, because
 * the two errors a wall-clock subtraction makes are opposite in sign and a test of one would
 * pass an implementation wrong in the other direction.
 */

it('is the lead before the start, in elapsed seconds, spelled in UTC', function () {
    $start = instantAt('2026-11-03 08:00:00');
    $sendAt = ReminderTime::of($start, 24, instantAt('2026-10-31 08:00:00'));

    expect($sendAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($sendAt?->getTimezone()->getName())->toBe('UTC')
        ->and($sendAt?->format('Y-m-d\TH:i:sP'))->toBe('2026-11-02T08:00:00+00:00')
        ->and($start->getTimestamp() - (int) $sendAt?->getTimestamp())->toBe(24 * 3600)
        ->and(ReminderTime::of($start, 48, instantAt('2026-10-31 08:00:00'))?->format('Y-m-d\TH:i:sP'))->toBe('2026-11-01T08:00:00+00:00');
});

it('keeps the microseconds of the start, because the subtraction is exact and rounds nothing, although nothing that reads the answer needs them', function () {
    $start = instantAt('2026-11-03 08:00:00.250000');

    expect(ReminderTime::of($start, 24, instantAt('2026-10-31 08:00:00'))?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-11-02T08:00:00.250000+00:00');
});

it('answers no instant at all once now has reached the send instant, because a reminder sent on the spot is not a reminder', function () {
    $start = instantAt('2026-11-03 08:00:00');

    expect(ReminderTime::of($start, 24, instantAt('2026-11-02 07:59:59')))->not->toBeNull()
        ->and(ReminderTime::of($start, 24, instantAt('2026-11-02 08:00:00')))->toBeNull()
        ->and(ReminderTime::of($start, 24, instantAt('2026-11-02 12:00:00')))->toBeNull()
        // A start already behind now is the same case at its furthest.
        ->and(ReminderTime::of($start, 24, instantAt('2026-11-04 08:00:00')))->toBeNull();
});

it('refuses a lead below one hour, because zero is the setting for no reminder and a caller asking for its instant has misread the setting', function (int $lead) {
    expect(fn () => ReminderTime::of(instantAt('2026-11-03 08:00:00'), $lead, instantAt('2026-10-31 08:00:00')))
        ->toThrow(InvalidArgumentException::class, 'lead');
})->with([0, -1]);

it('counts elapsed hours across the autumn clock change, so a start on the Sunday is reminded 24 hours and not 25 before', function () {
    // 10:00 on the Sunday is already EET, +02:00, so the start is 08:00 UTC. Twenty-four hours
    // earlier is 08:00 UTC on the Saturday, which the wall clock still on EEST calls 11:00. A
    // calendar day earlier, 10:00 on the Saturday, is 07:00 UTC and twenty-five hours before.
    $start = instantAt('2026-10-25 10:00:00', 'Europe/Athens');
    $sendAt = ReminderTime::of($start, 24, instantAt('2026-10-23 06:00:00'));

    expect($start->format('P'))->toBe('+02:00')
        ->and($sendAt?->format('Y-m-d\TH:i:sP'))->toBe('2026-10-24T08:00:00+00:00')
        ->and($sendAt?->setTimezone(new DateTimeZone('Europe/Athens'))->format('l j F Y \a\t H:i P'))->toBe('Saturday 24 October 2026 at 11:00 +03:00')
        ->and($start->getTimestamp() - (int) $sendAt?->getTimestamp())->toBe(24 * 3600);
});

it('counts elapsed hours across the spring clock change, so a start on the Sunday is reminded 24 hours and not 23 before', function () {
    // 10:00 on the Sunday is EEST, +03:00, so the start is 07:00 UTC. Twenty-four hours earlier
    // is 07:00 UTC on the Saturday, which the wall clock still on EET calls 09:00.
    $start = instantAt('2026-03-29 10:00:00', 'Europe/Athens');
    $sendAt = ReminderTime::of($start, 24, instantAt('2026-03-27 06:00:00'));

    expect($start->format('P'))->toBe('+03:00')
        ->and($sendAt?->format('Y-m-d\TH:i:sP'))->toBe('2026-03-28T07:00:00+00:00')
        ->and($sendAt?->setTimezone(new DateTimeZone('Europe/Athens'))->format('l j F Y \a\t H:i P'))->toBe('Saturday 28 March 2026 at 09:00 +02:00')
        ->and($start->getTimestamp() - (int) $sendAt?->getTimestamp())->toBe(24 * 3600);
});

it('reads only the position on the timeline of the instants it is given, whatever zone they were spelled in', function () {
    $athens = ReminderTime::of(instantAt('2026-11-03 10:00:00', 'Europe/Athens'), 24, instantAt('2026-10-31 10:00:00', 'Europe/Athens'));
    $utc = ReminderTime::of(instantAt('2026-11-03 08:00:00'), 24, instantAt('2026-10-31 08:00:00'));

    expect($athens?->getTimestamp())->toBe($utc?->getTimestamp())
        ->and($athens?->getTimezone()->getName())->toBe('UTC');
});
