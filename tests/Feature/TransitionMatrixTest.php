<?php

use App\Application\Clock;
use App\Application\Event\TransitionEvent;
use App\Application\TransitionRefused;
use App\Domain\Audit\Actor;
use App\Domain\Enum\BookingOutcome;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\ExpireHoldsFactory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * Every transition against every state, answering allowed or the refusal it names, and no refusal
 * leaving a trace.
 *
 * Twelve rows and eleven acts. The rows are the nine booking states with the three held ones
 * taken twice, once with a live expiry and once past it, because a lapsed hold is a different
 * answer from a live one under several of the acts and the same row under the rest. The acts are
 * the seven guarded transitions, the move, and the sweep, with the cancel and the move each run
 * twice because the actor changes the answer. The cancel is the one act a per-service window
 * binds the customer to and not the owner. The move is the customer's and the business's, and it
 * is the same: the owner's move is bound by the status rule alone, so it lands from every live
 * state where the customer's is refused by the window.
 *
 * What the owner's cancel column buys is the allowed cells, and not, the other way round, the
 * window_closed ones. At this clock the customer is past every window a service can set, so a
 * customer-only column would answer window_closed from every state whose status allows the act
 * and would never once show it landing. The owner is bound by the status rule alone, so their
 * column is the only place in the matrix where a cancel is allowed at all, and it is what makes
 * the distinction between a refusal by status and a refusal by clock visible in both directions.
 * A lapsed request is refused on approve and reject as well as on the move: the approval window
 * binds on both of the owner's surfaces.
 *
 * Every cell gets a booking of its own, and the schema is cleared between cells. Two of the acts
 * read more than the row they are aimed at: the sweep settles every lapsed hold of the tenant,
 * and a move that lands runs the reap inside its own transaction and may expire somebody else's
 * hold on the way. A matrix that seeded eleven bookings at once would have those two answering
 * for the others' rows.
 *
 * The clock stands an hour after the booking's own start, which is the one arrangement in which
 * all eleven acts ask their own question: an outcome may only be marked on a booking that has
 * begun, and a customer an hour past the start is inside every window a service can set, so
 * their cancel and their move are refused by the window while the owner's are not.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00, so the 15:00 a move asks for is inside her afternoon.
 */

const MATRIX_NOW = '2026-11-03 09:00:00';

const MATRIX_START = '2026-11-03 10:00';

const MATRIX_TARGET = '2026-11-03 15:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(StaffDayMaskCache::class, coldMaskCache());
    app()->instance(Clock::class, FixedClock::at(MATRIX_NOW));
});

afterEach(function () {
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

/**
 * One act against one booking, as the answer it gives: allowed, the machine code of the refusal
 * it names, or, for the sweep, whether the booking moved. The sweep has no refusal vocabulary,
 * because it is not a guarded transition and asks no guard: it selects by predicate, so what it
 * answers of a booking it did not select is that it left it alone.
 */
function matrixAnswer(string $act, string $uuid): string
{
    $tenant = LunaFixture::tenant();
    $factory = app(BookingTransitionFactory::class);
    $target = LunaFixture::at(MATRIX_TARGET);

    if ($act === 'sweep') {
        $before = LunaFixture::row($uuid);
        app(ExpireHoldsFactory::class)->for($tenant)->sweep();

        return LunaFixture::row($uuid) === $before ? 'untouched' : 'allowed';
    }

    try {
        match ($act) {
            'confirm' => $factory->confirm($tenant)->confirm($uuid, Actor::customer()),
            'release' => $factory->release($tenant)->release($uuid),
            'cancel.customer' => $factory->cancel($tenant)->cancel($uuid, Actor::customer()),
            'cancel.user' => $factory->cancel($tenant)->cancel($uuid, Actor::user(7)),
            'approve' => $factory->approve($tenant)->approve($uuid, Actor::user(7)),
            'reject' => $factory->reject($tenant)->reject($uuid, Actor::user(7)),
            'completed' => $factory->markOutcome($tenant)->mark($uuid, BookingOutcome::Completed, Actor::user(7)),
            'no_show' => $factory->markOutcome($tenant)->mark($uuid, BookingOutcome::NoShow, Actor::user(7)),
            'reschedule.user' => $factory->reschedule($tenant)->reschedule($uuid, Actor::user(7), $target),
            'reschedule.customer' => $factory->reschedule($tenant)->reschedule($uuid, Actor::customer(), $target),
            default => throw new LogicException(sprintf('The matrix has no act named "%s".', $act)),
        };
    } catch (TransitionRefused $refused) {
        return $refused->reason->value;
    }

    return 'allowed';
}

/**
 * What one act did to one freshly seeded booking: its answer, how many audit rows it left and
 * how many records it published.
 *
 * The counts are of the whole schema, which is the whole of one booking: the rows are cleared
 * before the act, so anything counted afterwards is this act's.
 *
 * @return array{string, int, int}
 */
function matrixCell(string $act, string $status, ?string $holdClass, string $expiry, bool $blocks): array
{
    DB::table('tenant_luna.audit_log')->delete();
    DB::table('tenant_luna.bookings')->delete();

    $uuid = LunaFixture::booking(
        $status,
        holdClass: $holdClass,
        expiry: $expiry,
        start: MATRIX_START,
        blocks: $blocks,
        requiresApproval: $status === 'awaiting_approval',
    );

    $published = 0;
    Event::listen(TransitionEvent::class, function () use (&$published): void {
        $published++;
    });

    $answer = matrixAnswer($act, $uuid);

    return [$answer, DB::table('tenant_luna.audit_log')->count(), $published];
}

/**
 * What a cell must look like, from the answer alone: an act that was allowed writes exactly one
 * audit row and publishes exactly one record, and an act that was refused, or a sweep that left
 * the booking alone, writes nothing and publishes nothing.
 *
 * The rule holds only because each cell is one booking: a move that lands may expire somebody
 * else's lapsed hold inside its own transaction and announce that too, so "writes no audit row
 * and publishes nothing" is an assertion about refusals, never about transitions in general.
 *
 * @return array{string, int, int}
 */
function matrixExpected(string $answer): array
{
    return $answer === 'allowed' ? ['allowed', 1, 1] : [$answer, 0, 0];
}

/**
 * The eleven acts, in the order the matrix reads them.
 *
 * @return list<string>
 */
function matrixActs(): array
{
    return [
        'confirm',
        'release',
        'cancel.customer',
        'cancel.user',
        'approve',
        'reject',
        'completed',
        'no_show',
        'reschedule.user',
        'reschedule.customer',
        'sweep',
    ];
}

it('answers every act from every state with allowed or the refusal it names, and a refusal writes no audit row and publishes nothing', function (string $status, ?string $holdClass, string $expiry, bool $blocks, array $expected) {
    expect(array_keys($expected))->toBe(matrixActs());

    $observed = [];
    $wanted = [];

    foreach (matrixActs() as $act) {
        $observed[$act] = matrixCell($act, $status, $holdClass, $expiry, $blocks);
        $wanted[$act] = matrixExpected(is_string($expected[$act]) ? $expected[$act] : '');
    }

    expect($observed)->toBe($wanted);
})->with([
    // A checkout hold the customer is still inside. Everything the customer may do to their own
    // hold, they may do; the owner may cancel it and never move it, since the customer is still
    // inside the booking flow; nobody may approve what was never asked of them; and the sweep
    // leaves a hold that has not run out alone.
    'pending, live' => ['pending', 'checkout', 'future', true, [
        'confirm' => 'allowed',
        'release' => 'allowed',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'window_closed',
        'sweep' => 'untouched',
    ]],
    // The same hold a minute past its expiry. The confirm is the one act answered 410, which is the
    // booking app's signal to force its own expiry flag; the release is still allowed, because the
    // slot is free by the clock either way and "released by the customer" is the truer account; and
    // the customer's move is refused as hold_lapsed rather than as not_reschedulable, which asserts
    // a standing property of a booking that was movable a minute ago; the owner's is
    // not_reschedulable, as on the live hold.
    'pending, lapsed' => ['pending', 'checkout', 'past', true, [
        'confirm' => 'hold_expired',
        'release' => 'allowed',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'hold_lapsed',
        'sweep' => 'allowed',
    ]],
    // A request in front of the owner. The release refuses, which is what lets a lodged request
    // survive the customer's tab closing.
    'awaiting_approval, live' => ['awaiting_approval', 'approval', 'future', true, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'allowed',
        'reject' => 'allowed',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'allowed',
        'reschedule.customer' => 'window_closed',
        'sweep' => 'untouched',
    ]],
    // A request whose window closed and that nobody has reaped. The owner may no longer decide
    // it either way: the window binds the admin's buttons as it binds the decision pages, so the
    // sweep alone settles it, and its service's policy is expire.
    'awaiting_approval, lapsed' => ['awaiting_approval', 'approval', 'past', true, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'hold_lapsed',
        'reschedule.customer' => 'hold_lapsed',
        'sweep' => 'allowed',
    ]],
    'awaiting_payment, live' => ['awaiting_payment', 'payment', 'future', true, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'allowed',
        'reschedule.customer' => 'window_closed',
        'sweep' => 'untouched',
    ]],
    'awaiting_payment, lapsed' => ['awaiting_payment', 'payment', 'past', true, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'hold_lapsed',
        'reschedule.customer' => 'hold_lapsed',
        'sweep' => 'allowed',
    ]],
    // The booking is sold and has begun, so the two outcomes are the owner's to record and
    // nothing about a hold applies any more.
    'confirmed' => ['confirmed', null, 'none', true, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'window_closed',
        'cancel.user' => 'allowed',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'allowed',
        'no_show' => 'allowed',
        'reschedule.user' => 'allowed',
        'reschedule.customer' => 'window_closed',
        'sweep' => 'untouched',
    ]],
    // The four settled states answer the same way to everything: the status rule refuses before
    // any window or clock is consulted, so even the customer past every window is told what is
    // true of the booking rather than what is true of the hour.
    'completed' => ['completed', null, 'none', false, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'not_cancellable',
        'cancel.user' => 'not_cancellable',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'not_reschedulable',
        'sweep' => 'untouched',
    ]],
    'no_show' => ['no_show', null, 'none', false, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'not_cancellable',
        'cancel.user' => 'not_cancellable',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'not_reschedulable',
        'sweep' => 'untouched',
    ]],
    'cancelled' => ['cancelled', null, 'none', false, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'not_cancellable',
        'cancel.user' => 'not_cancellable',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'not_reschedulable',
        'sweep' => 'untouched',
    ]],
    'rejected' => ['rejected', null, 'none', false, [
        'confirm' => 'not_confirmable',
        'release' => 'not_releasable',
        'cancel.customer' => 'not_cancellable',
        'cancel.user' => 'not_cancellable',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'not_reschedulable',
        'sweep' => 'untouched',
    ]],
    // A hold the sweep or a reap already settled. Only the confirm tells it apart from the four
    // above, and it answers 410 rather than 409, because a hold that ran out and a booking the
    // sweeper reached are the same thing at two moments.
    'expired' => ['expired', null, 'none', false, [
        'confirm' => 'hold_expired',
        'release' => 'not_releasable',
        'cancel.customer' => 'not_cancellable',
        'cancel.user' => 'not_cancellable',
        'approve' => 'not_approvable',
        'reject' => 'not_rejectable',
        'completed' => 'not_confirmed',
        'no_show' => 'not_confirmed',
        'reschedule.user' => 'not_reschedulable',
        'reschedule.customer' => 'not_reschedulable',
        'sweep' => 'untouched',
    ]],
]);

it('covers every state of the machine and every act that settles one', function () {
    $states = [];

    foreach (BookingStatus::cases() as $status) {
        $states[] = $status->value;
    }

    // The nine booking states, each named by at least one row of the matrix above, and the
    // three held ones named twice.
    expect($states)->toHaveCount(9)
        ->and(matrixActs())->toHaveCount(11);
});
