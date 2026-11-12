<?php

use App\Application\Clock;
use App\Application\Event\BookingRejected;
use App\Application\RejectBooking;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
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
 * RejectBooking on luna's real rows through the factory: the owner says no to a request lodged
 * with them, the slot goes back to the diary, and the note they wrote about it stays on the
 * booking and out of the trail.
 *
 * Not wrapped in a transaction: every transition enters the transaction runner, which refuses
 * to open inside another.
 */

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00:00'));
});

afterEach(function () {
    DB::purge('reject_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function rejectUseCase(): RejectBooking
{
    return app(BookingTransitionFactory::class)->reject(LunaFixture::tenant());
}

/**
 * A request lodged with the owner, taken the way the booking page takes one.
 */
function rejectRequested(string $start = FixtureDay::DATE.' 09:00'): string
{
    return LunaFixture::hold(['Bridal trial'], $start);
}

it('rejects a request: it lands rejected, the hold is cleared, every item stops blocking, the trail says awaiting_approval to rejected by that user, and the rejected event goes out after the commit', function () {
    $seen = [];
    Event::listen(BookingRejected::class, function (BookingRejected $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => LunaFixture::probe('reject_probe')->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $uuid = rejectRequested();

    rejectUseCase()->reject($uuid, Actor::user(7), 'Fully booked that morning.');

    $trail = LunaFixture::trail();

    expect(LunaFixture::row($uuid))->toMatchArray([
        'status' => 'rejected',
        'hold_class' => null,
        'hold_expires_at' => null,
        'approved_at' => null,
        'approved_by' => null,
        'rejection_reason' => 'Fully booked that morning.',
        'blocks' => [false],
    ])
        ->and($trail)->toHaveCount(2)
        ->and($trail[1])->toMatchArray(['uuid' => $uuid, 'action' => 'rejected', 'actor' => 'user:7'])
        ->and($trail[1]['payload'])->toBe(['hold_class' => 'approval', 'status_from' => 'awaiting_approval', 'status_to' => 'rejected'])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'from' => 'awaiting_approval',
            'to' => 'rejected',
            'hold_class' => 'approval',
            'actor' => 'user:7',
            'level' => 0,
            'committed' => 'rejected',
        ]]);
});

it('stores the reason exactly as it was given, {case}, and names it nowhere in the trail', function (?string $reason, ?string $stored) {
    $uuid = rejectRequested();

    rejectUseCase()->reject($uuid, Actor::user(7), $reason);

    $payload = LunaFixture::trail()[1]['payload'] ?? null;

    expect(LunaFixture::row($uuid))->toMatchArray(['status' => 'rejected', 'rejection_reason' => $stored])
        ->and(is_array($payload) ? array_keys($payload) : null)->toBe(['hold_class', 'status_from', 'status_to']);
})->with([
    // An empty reason must never block a rejection, so the empty string is a reason the owner
    // gave and reaches the column as itself rather than as a null.
    'a sentence' => ['Fully booked that morning.', 'Fully booked that morning.'],
    'the empty string' => ['', ''],
    'no reason at all' => [null, null],
]);

it('refuses a request whose window closed and that nobody has swept yet, as not_rejectable, for the approval\'s reason: the window binds the buttons as it binds the decision pages', function () {
    $uuid = LunaFixture::booking(
        'awaiting_approval',
        holdClass: 'approval',
        expiry: 'past',
        blocks: true,
        service: 'Bridal trial',
        requiresApproval: true,
    );
    $before = LunaFixture::row($uuid);

    $refused = LunaFixture::refusal(fn () => rejectUseCase()->reject($uuid, Actor::user(7), 'Too late to prepare.'));

    expect($refused->reason)->toBe(TransitionRefusal::NotRejectable)
        ->and($refused->status->value)->toBe('awaiting_approval')
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toBe([]);
});

it('rejects a request whose window is still ahead of the transaction\'s clock', function () {
    $uuid = LunaFixture::booking('awaiting_approval', 'approval', 'future', service: 'Bridal trial', requiresApproval: true);

    rejectUseCase()->reject($uuid, Actor::user(7), 'Fully booked.');

    expect(LunaFixture::row($uuid))->toMatchArray(['status' => 'rejected', 'rejection_reason' => 'Fully booked.']);
});

it('refuses every state that is not a request waiting on the owner, leaving the row as it was with no trail and nothing published', function (string $status, ?string $holdClass, string $expiry, bool $blocks) {
    $published = [];
    Event::listen(BookingRejected::class, function () use (&$published): void {
        $published[] = 'rejected';
    });

    $uuid = LunaFixture::booking($status, $holdClass, $expiry, blocks: $blocks);
    $before = LunaFixture::row($uuid);

    $refused = LunaFixture::refusal(fn () => rejectUseCase()->reject($uuid, Actor::user(7), 'No.'));

    expect($refused->reason)->toBe(TransitionRefusal::NotRejectable)
        ->and($refused->status->value)->toBe($status)
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toBe([])
        ->and($published)->toBe([]);
})->with([
    'a request already reaped' => ['expired', null, 'past', false],
    'a hold still in the booking flow' => ['pending', 'checkout', 'future', true],
    'an approved booking waiting to be paid' => ['awaiting_payment', 'payment', 'future', true],
    'a booking already confirmed' => ['confirmed', null, 'none', true],
    'a booking that has happened' => ['completed', null, 'none', false],
    'a booking nobody turned up for' => ['no_show', null, 'none', false],
    'a booking the customer cancelled' => ['cancelled', null, 'none', false],
    'a request the owner already rejected' => ['rejected', null, 'none', false],
]);

it('issues, for one rejection, the staff-day lock, the booking row lock, the re-read, one compare-and-set writing the reason beside the status, the unblock, the trail, and the commit', function () {
    $uuid = rejectRequested();
    // Built before the trace starts, so what it records is the transition and not the tenant
    // read every caller does before it has a use case at all.
    $rejects = rejectUseCase();
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = $query->sql;
    });

    $rejects->reject($uuid, Actor::user(7), 'Fully booked that morning.');

    expect($trace)->toContain('update bookings set status = ?, updated_at = now(), manage_token_sealed = null, hold_class = ?, hold_expires_at = ?::timestamptz, rejection_reason = ? where id = ? and status = ?')
        ->and($trace)->toContain(BlockingItems::unblockSql())
        ->and(array_filter($trace, fn (string $statement): bool => str_contains($statement, 'approved_at')))->toBe([]);
});

it('holds the privacy assertion over a rejection whose reason names the customer outright: the note is on the booking, where erasure reaches it, and nowhere in the trail, which has no erasure step', function () {
    $uuid = rejectRequested();
    $reason = sprintf('%s (%s) asked us to hold it for her sister instead.', LunaFixture::CUSTOMER_NAME, LunaFixture::CUSTOMER_EMAIL);

    rejectUseCase()->reject($uuid, Actor::user(7), $reason);

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_NAME)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_PHONE)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('rejection_reason'))->toBe($reason);
});
