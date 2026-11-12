<?php

use App\Application\Clock;
use App\Application\Event\BookingCompleted;
use App\Application\Event\BookingNoShow;
use App\Application\MarkBookingOutcome;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingOutcome;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\BlockingItems;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * MarkBookingOutcome on luna's real rows through the factory: the owner says whether an
 * appointment happened, once it is no longer in the future.
 *
 * Not wrapped in a transaction: every transition enters the transaction runner, which refuses
 * to open inside another.
 *
 * The booking under test is held and confirmed on the Monday and marked on the Tuesday, which
 * is the real path rather than a row written into the state: the clock moves between the two,
 * and moving it is what makes the appointment past.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    outcomeClockAt('2026-11-02 06:00:00');
});

afterEach(function () {
    DB::purge('outcome_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * The instant every use case built after this call reads as now. The factory is resolved fresh
 * each time, so moving the clock moves what the next transition compares a start against.
 */
function outcomeClockAt(string $utc): void
{
    app()->instance(Clock::class, FixedClock::at($utc));
}

function outcomeUseCase(): MarkBookingOutcome
{
    return app(BookingTransitionFactory::class)->markOutcome(LunaFixture::tenant());
}

/**
 * A confirmed booking, settled by the customer the way the booking page settles one, at the
 * Athens wall-clock start given.
 */
function outcomeConfirmed(string $start = FixtureDay::START): string
{
    $uuid = LunaFixture::hold(['Haircut'], $start);
    app(BookingTransitionFactory::class)->confirm(LunaFixture::tenant())->confirm($uuid, Actor::customer());

    return $uuid;
}

it('marks a confirmed booking whose start has passed as {case}: the status lands, every item stops blocking, the trail names the outcome, and the matching record goes out after the commit', function (BookingOutcome $outcome, string $status, string $announced, string $other) {
    $seen = [];
    Event::listen($announced, function (object $event) use (&$seen): void {
        // The listener is registered by a class name the dataset carries, so the closure
        // takes both records rather than one of them by name. There is no shared type to name
        // instead: the shape is a trait, so that nobody writes a subscription against it.
        /** @var BookingCompleted|BookingNoShow $event */
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => LunaFixture::probe('outcome_probe')->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });
    $wrong = [];
    Event::listen($other, function () use (&$wrong): void {
        $wrong[] = 'announced';
    });

    $uuid = outcomeConfirmed();
    outcomeClockAt('2026-11-03 09:00:00');

    outcomeUseCase()->mark($uuid, $outcome, Actor::user(7));

    $trail = LunaFixture::trail();

    expect(LunaFixture::row($uuid))->toMatchArray([
        'status' => $status,
        'hold_class' => null,
        'hold_expires_at' => null,
        'approved_at' => null,
        'approved_by' => null,
        'rejection_reason' => null,
        'blocks' => [false],
    ])
        ->and($trail)->toHaveCount(3)
        ->and($trail[2])->toMatchArray(['uuid' => $uuid, 'action' => $status, 'actor' => 'user:7'])
        // No hold class: a confirmed booking is under no hold, so the trail records none
        // rather than a null.
        ->and($trail[2]['payload'])->toBe(['status_from' => 'confirmed', 'status_to' => $status])
        ->and($wrong)->toBe([])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'from' => 'confirmed',
            'to' => $status,
            'hold_class' => null,
            'actor' => 'user:7',
            'level' => 0,
            'committed' => $status,
        ]]);
})->with([
    'completed' => [BookingOutcome::Completed, 'completed', BookingCompleted::class, BookingNoShow::class],
    'a no-show' => [BookingOutcome::NoShow, 'no_show', BookingNoShow::class, BookingCompleted::class],
]);

it('allows the outcome at the very instant the appointment starts and refuses a second before it, because the half-open boundary is the one every range in this codebase uses', function () {
    $uuid = outcomeConfirmed('2026-11-03 10:00');

    // 10:00 in Athens is 08:00 UTC in November.
    outcomeClockAt('2026-11-03 07:59:59');
    $refused = LunaFixture::refusal(fn () => outcomeUseCase()->mark($uuid, BookingOutcome::Completed, Actor::user(7)));

    expect($refused->reason)->toBe(TransitionRefusal::TooEarly)
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'confirmed', 'blocks' => [true]])
        ->and(LunaFixture::trail())->toHaveCount(2);

    outcomeClockAt('2026-11-03 08:00:00');
    outcomeUseCase()->mark($uuid, BookingOutcome::Completed, Actor::user(7));

    expect(LunaFixture::row($uuid))->toMatchArray(['status' => 'completed', 'blocks' => [false]]);
});

it('refuses {case} of a booking that has not started, writing nothing and publishing nothing, because whether anybody turned up is not yet a fact', function (BookingOutcome $outcome, string $announced) {
    $published = [];
    Event::listen($announced, function () use (&$published): void {
        $published[] = 'announced';
    });

    $uuid = outcomeConfirmed('2026-11-03 10:00');
    $before = LunaFixture::row($uuid);

    $refused = LunaFixture::refusal(fn () => outcomeUseCase()->mark($uuid, $outcome, Actor::user(7)));

    expect($refused->reason)->toBe(TransitionRefusal::TooEarly)
        ->and($refused->status->value)->toBe('confirmed')
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toHaveCount(2)
        ->and($published)->toBe([]);
})->with([
    'completed' => [BookingOutcome::Completed, BookingCompleted::class],
    'a no-show' => [BookingOutcome::NoShow, BookingNoShow::class],
]);

it('measures from the first segment\'s own start and not from the end of the chain, so a chain that has begun may be marked while it is still running', function () {
    $uuid = LunaFixture::hold(['Haircut', 'Colour'], '2026-11-03 09:00');
    app(BookingTransitionFactory::class)->confirm(LunaFixture::tenant())->confirm($uuid, Actor::customer());

    // 09:00 in Athens is 07:00 UTC. The chain runs well past this; its first segment does not.
    outcomeClockAt('2026-11-03 07:00:00');
    outcomeUseCase()->mark($uuid, BookingOutcome::Completed, Actor::user(7));

    expect(LunaFixture::row($uuid))->toMatchArray(['status' => 'completed', 'blocks' => [false, false]]);
});

it('refuses every state that is not confirmed, whatever the clock says, leaving the row as it was with no trail and nothing published', function (string $status, ?string $holdClass, string $expiry, bool $blocks) {
    $published = [];
    Event::listen(BookingCompleted::class, function () use (&$published): void {
        $published[] = 'announced';
    });

    // The clock stands before the start, so a status that refused for the clock's sake would
    // answer too_early here. Every one of these answers not_confirmed, which is the order the
    // guard asks its two questions in.
    $uuid = LunaFixture::booking($status, $holdClass, $expiry, blocks: $blocks);
    $before = LunaFixture::row($uuid);

    $refused = LunaFixture::refusal(fn () => outcomeUseCase()->mark($uuid, BookingOutcome::Completed, Actor::user(7)));

    expect($refused->reason)->toBe(TransitionRefusal::NotConfirmed)
        ->and($refused->status->value)->toBe($status)
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toBe([])
        ->and($published)->toBe([]);
})->with([
    'a hold still in the booking flow' => ['pending', 'checkout', 'future', true],
    'a request waiting on the owner' => ['awaiting_approval', 'approval', 'future', true],
    'an approved booking waiting to be paid' => ['awaiting_payment', 'payment', 'future', true],
    'a booking already marked' => ['completed', null, 'none', false],
    'a booking already marked a no-show' => ['no_show', null, 'none', false],
    'a booking the customer cancelled' => ['cancelled', null, 'none', false],
    'a request the owner rejected' => ['rejected', null, 'none', false],
    'a hold the sweeper expired' => ['expired', null, 'past', false],
]);

it('issues, for one outcome, the staff-day lock, the booking row lock, the re-read, one compare-and-set naming the status the re-read found and writing no other column, the unblock, the trail, and the commit', function () {
    $uuid = outcomeConfirmed();
    outcomeClockAt('2026-11-03 09:00:00');
    // Built before the trace starts, so what it records is the transition and not the tenant
    // read every caller does before it has a use case at all.
    $marks = outcomeUseCase();
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = $query->sql;
    });

    $marks->mark($uuid, BookingOutcome::NoShow, Actor::user(7));

    expect($trace)->toContain('update bookings set status = ?, updated_at = now(), manage_token_sealed = null where id = ? and status = ?')
        ->and($trace)->toContain(BlockingItems::unblockSql())
        ->and(array_filter($trace, fn (string $statement): bool => str_contains($statement, 'hold_class = ?')))->toBe([]);
});

it('holds the privacy assertion over an outcome: no actor and no payload of the trail names the customer who booked', function () {
    $uuid = outcomeConfirmed();
    outcomeClockAt('2026-11-03 09:00:00');

    outcomeUseCase()->mark($uuid, BookingOutcome::NoShow, Actor::user(7));

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_NAME)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_PHONE);
});
