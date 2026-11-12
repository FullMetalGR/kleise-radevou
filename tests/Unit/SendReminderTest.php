<?php

use App\Jobs\SendReminder;
use Tests\Support\FixtureDay;

/*
 * What makes one reminder job the same as another, to the lock that drops a second hand-over
 * of one state while a job for it waits: the tenant, the booking, the revision and the start,
 * and each of the four alone tells two jobs apart. The start is in the key beside the
 * revision because a start and a revision read across a move that changed the start share
 * the revision with the row's true state, and a job carrying such a pair, which will skip,
 * would otherwise take the lock from the one that will send.
 *
 * A job that names no start carries the fixture's default start, FixtureDay, as the UTC text
 * the listener hands over; the first case spells that text out, so the key's form is pinned.
 */

function reminderJob(int $tenant = 7, string $uuid = '0d8b2f6e-4c1a-4f2e-9d3b-1a2b3c4d5e6f', int $revision = 42, ?string $start = null): SendReminder
{
    $start ??= FixtureDay::start()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');

    return new SendReminder($tenant, $uuid, $start, $revision);
}

it('is unique on the tenant, the booking, the revision and the start together', function () {
    expect(reminderJob()->uniqueId())->toBe('7:0d8b2f6e-4c1a-4f2e-9d3b-1a2b3c4d5e6f:42:2026-11-03T08:00:00.000000+00:00')
        ->and(reminderJob()->uniqueId())->toBe(reminderJob()->uniqueId());
});

it('tells two jobs apart by {part} alone', function (SendReminder $other) {
    expect($other->uniqueId())->not->toBe(reminderJob()->uniqueId());
})->with([
    'the tenant' => fn () => reminderJob(tenant: 8),
    'the booking' => fn () => reminderJob(uuid: '1e9c3a7f-5d2b-4a3f-8e4c-2b3c4d5e6f70'),
    'the revision' => fn () => reminderJob(revision: 43),
    'the start' => fn () => reminderJob(start: '2026-11-04T13:00:00.000000+00:00'),
]);

it('stays unique for the window between a commit and its listeners reads, and not for the days the job waits', function () {
    expect(reminderJob()->uniqueFor())->toBe(3600)
        ->and(SendReminder::UNIQUE_FOR_SECONDS)->toBe(3600);
});
