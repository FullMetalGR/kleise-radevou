<?php

use App\Application\ApproveBooking;
use App\Application\Clock;
use App\Application\Event\BookingApproved;
use App\Application\GuardedWrite;
use App\Application\Payment\NullPaymentProvider;
use App\Application\Payment\SettlesByProvider;
use App\Application\Repository\BookingLookup;
use App\Domain\Audit\Actor;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Cache\StaffDayMaskCache;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\BlockingItems;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\PostgresBookingLookup;
use App\Infrastructure\Db\PostgresBookingRepository;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\Db\TransactionRunner;
use App\Infrastructure\Events\TransitionPublishers;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FixedClock;
use Tests\Support\FixtureDay;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * ApproveBooking on luna's real rows through the factory: the owner says yes to a request
 * lodged with them, and the slot the request was holding becomes the customer's for good.
 *
 * Not wrapped in a transaction: every transition enters the transaction runner, which refuses
 * to open inside another.
 *
 * Bridal trial is luna's one service that requires approval, 120 minutes with Elena, so a hold
 * on it lands awaiting_approval on an approval hold the way a customer's request does.
 */

const APPROVE_AT = '2026-11-02 06:00:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(APPROVE_AT));
});

afterEach(function () {
    DB::purge('approve_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function approveUseCase(): ApproveBooking
{
    return app(BookingTransitionFactory::class)->approve(LunaFixture::tenant());
}

/**
 * A request lodged with the owner, taken the way the booking page takes one.
 */
function approveRequested(string $start = FixtureDay::DATE.' 09:00'): string
{
    return LunaFixture::hold(['Bridal trial'], $start);
}

/**
 * The instant a column holds, read in UTC and spelled the way an audit payload spells one, so a
 * test names one instant whatever offset the session happened to write it with. The zone is
 * named on the way in as well as out, and the conversion is what makes the two spellings of the
 * same moment one string.
 */
function approveStamp(mixed $text): string
{
    expect($text)->toBeString();
    assert(is_string($text));

    return (new DateTimeImmutable($text, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s.uP');
}

it('approves a request lodged with the owner: it lands confirmed, approved_at and approved_by say who decided and when, the hold is cleared, every item still blocks, the trail says awaiting_approval to confirmed by that user, and the approved event goes out after the commit', function () {
    $seen = [];
    Event::listen(BookingApproved::class, function (BookingApproved $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => LunaFixture::probe('approve_probe')->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $uuid = approveRequested();
    $before = LunaFixture::row($uuid);

    approveUseCase()->approve($uuid, Actor::user(7));

    $after = LunaFixture::row($uuid);
    $trail = LunaFixture::trail();

    expect($before)->toMatchArray([
        'status' => 'awaiting_approval',
        'hold_class' => 'approval',
        'approved_at' => null,
        'approved_by' => null,
        'blocks' => [true],
    ])
        ->and($after)->toMatchArray([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'approved_by' => 7,
            'rejection_reason' => null,
            'blocks' => [true],
            'total_minor' => $before['total_minor'],
        ])
        ->and(approveStamp($after['approved_at']))->toBe('2026-11-02T06:00:00.000000+00:00')
        ->and($trail)->toHaveCount(2)
        ->and($trail[1])->toMatchArray(['uuid' => $uuid, 'action' => 'approved', 'actor' => 'user:7'])
        ->and($trail[1]['payload'])->toBe(['hold_class' => 'approval', 'status_from' => 'awaiting_approval', 'status_to' => 'confirmed'])
        ->and($seen)->toBe([[
            'uuid' => $uuid,
            'from' => 'awaiting_approval',
            'to' => 'confirmed',
            'hold_class' => 'approval',
            'actor' => 'user:7',
            'level' => 0,
            'committed' => 'confirmed',
        ]]);
});

it('records no user at all when the decision was not a person\'s, which is the engine or a job granting a request the owner never answered', function (Actor $actor, string $spelling) {
    $uuid = approveRequested();

    approveUseCase()->approve($uuid, $actor);

    expect(LunaFixture::row($uuid))->toMatchArray(['status' => 'confirmed', 'approved_by' => null])
        ->and(approveStamp(LunaFixture::row($uuid)['approved_at']))->toBe('2026-11-02T06:00:00.000000+00:00')
        ->and(LunaFixture::trail()[1]['actor'] ?? null)->toBe($spelling);
})->with([
    'the engine itself' => [Actor::system(), 'system'],
    'a queued job' => [Actor::job('expire-holds'), 'job:expire-holds'],
]);

it('refuses a request whose window closed and that nobody has swept yet, as not_approvable, whatever its timeout policy, because the window binds the buttons as it binds the decision pages', function (string $onTimeout) {
    $uuid = LunaFixture::booking(
        'awaiting_approval',
        holdClass: 'approval',
        expiry: 'past',
        blocks: true,
        service: 'Bridal trial',
        requiresApproval: true,
        onApprovalTimeout: $onTimeout,
    );
    $before = LunaFixture::row($uuid);

    $refused = LunaFixture::refusal(fn () => approveUseCase()->approve($uuid, Actor::user(7)));

    expect($refused->reason)->toBe(TransitionRefusal::NotApprovable)
        ->and($refused->status)->toBe(BookingStatus::AwaitingApproval)
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toBe([]);
})->with([
    // The policy decides what the sweeper makes of an unanswered request, and the sweeper alone
    // settles it once the window has closed: neither answer is the owner's to give late.
    'a request whose policy is to expire, not yet reaped' => ['expire'],
    'a request whose policy is to auto-approve, which blocks until it is decided' => ['auto_approve'],
]);

it('refuses a request whose window closed an instant before the transaction began, and admits one whose window is still ahead of the transaction\'s clock', function () {
    $closing = LunaFixture::booking('awaiting_approval', 'approval', 'future', service: 'Bridal trial', requiresApproval: true);
    $open = LunaFixture::booking('awaiting_approval', 'approval', 'future', start: '2026-11-03 11:00', service: 'Bridal trial', requiresApproval: true);

    // Closed by the database's clock in a statement of its own, so by the time the approval's
    // transaction reads now() the end is behind it: the window is live while its end is ahead
    // of the clock the transaction reads, and closed from the instant it is not.
    DB::update('update tenant_luna.bookings set hold_expires_at = now() where uuid = ?::uuid', [$closing]);

    $refused = LunaFixture::refusal(fn () => approveUseCase()->approve($closing, Actor::user(7)));
    approveUseCase()->approve($open, Actor::user(7));

    expect($refused->reason)->toBe(TransitionRefusal::NotApprovable)
        ->and(LunaFixture::row($open)['status'])->toBe('confirmed');
});

it('refuses every state that is not a request waiting on the owner, leaving the row as it was with no trail and nothing published', function (string $status, ?string $holdClass, string $expiry, bool $blocks) {
    $published = [];
    Event::listen(BookingApproved::class, function () use (&$published): void {
        $published[] = 'approved';
    });

    $uuid = LunaFixture::booking($status, $holdClass, $expiry, blocks: $blocks);
    $before = LunaFixture::row($uuid);

    $refused = LunaFixture::refusal(fn () => approveUseCase()->approve($uuid, Actor::user(7)));

    expect($refused->reason)->toBe(TransitionRefusal::NotApprovable)
        ->and($refused->status->value)->toBe($status)
        ->and(LunaFixture::row($uuid))->toBe($before)
        ->and(LunaFixture::trail())->toBe([])
        ->and($published)->toBe([]);
})->with([
    // A request the sweeper already reached is refused as not_approvable and never as the
    // confirm's 410: the owner is not the customer and the hold is not what they were asked
    // about.
    'a request already reaped' => ['expired', null, 'past', false],
    'a hold still in the booking flow' => ['pending', 'checkout', 'future', true],
    'an approved booking waiting to be paid' => ['awaiting_payment', 'payment', 'future', true],
    'a booking already confirmed' => ['confirmed', null, 'none', true],
    'a booking that has happened' => ['completed', null, 'none', false],
    'a booking nobody turned up for' => ['no_show', null, 'none', false],
    'a booking the customer cancelled' => ['cancelled', null, 'none', false],
    'a request the owner already rejected' => ['rejected', null, 'none', false],
]);

it('approves an online-mode request to confirmed, which is the degrade rule working rather than a gap: with no working provider an approval lands on confirmed rather than stranding a booking in a state nothing could ever pay for', function () {
    // Bridal trial is luna's approval service and its payment mode is online, so the request
    // this whole file approves is the one the degrade rule is about. Nothing is asked about
    // money on this path and nothing can refuse for it: where an approval lands once a tenant
    // can take money is not this file's question, and it is not a refusal there either.
    $uuid = approveRequested();

    approveUseCase()->approve($uuid, Actor::user(7));

    expect(LunaFixture::row($uuid))->toMatchArray([
        'status' => 'confirmed',
        'payment_mode' => 'online',
        'hold_class' => null,
        'hold_expires_at' => null,
        'approved_by' => 7,
        'blocks' => [true],
    ])
        ->and(LunaFixture::trail()[1]['action'] ?? null)->toBe('approved');
});

it('issues, for one approval, the staff-day lock, the booking row lock, the re-read, the tenant\'s payment connection row, one compare-and-set writing the approval columns beside the status, the trail, and the commit, with no unblock and nothing of its own after the commit', function () {
    $uuid = approveRequested();
    // Built before the trace starts, so what it records is the transition and not the tenant
    // read every caller does before it has a use case at all.
    $approves = approveUseCase();
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = $query->sql;
    });
    Event::listen(TransactionBeginning::class, function () use (&$trace): void {
        $trace[] = 'BEGIN';
    });
    Event::listen(TransactionCommitted::class, function () use (&$trace): void {
        $trace[] = 'COMMIT';
    });
    // Listened for so that the no-rollback assertion below is a claim about this transition and
    // not about this listener list. Nothing else can write ROLLBACK into the trace, and an
    // assertion that nothing can falsify proves nothing about the code it is written under.
    Event::listen(TransactionRolledBack::class, function () use (&$trace): void {
        $trace[] = 'ROLLBACK';
    });

    $approves->approve($uuid, Actor::user(7));

    $begin = array_search('BEGIN', $trace, true);
    $commit = array_search('COMMIT', $trace, true);

    // One transaction block, asserted before either anchor is used as an offset. array_search
    // answers false for a needle that is not there and false used as an offset is zero, so a
    // trace that never committed would otherwise be sliced from the top and reported as a wrong
    // first statement rather than as the missing commit it is.
    expect(array_keys($trace, 'BEGIN', true))->toHaveCount(1)
        ->and(array_keys($trace, 'COMMIT', true))->toHaveCount(1)
        ->and(array_keys($trace, 'ROLLBACK', true))->toBe([])
        ->and($begin)->toBeInt()
        ->and($commit)->toBeInt();

    $transaction = array_slice($trace, (int) $begin, (int) $commit - (int) $begin + 1);
    // What runs after that commit is two listeners: the customer's answer, which under the
    // suite's sync queue re-reads the booking inside the dispatch, and the reminder's
    // scheduling, which reads the settings row, the booking and its revision to place the
    // reminder and then, under the same sync queue, runs the reminder's own re-read inside
    // the dispatch. The business's notice is on the same record and contributes nothing here,
    // because the actor is a user and it returns on that before it reads anything. So the
    // slice below is those two listeners' reads, and it is empty if both stop running. Which
    // listeners fire on a record, and what each of them sends, is AnsweredRequestSeamTest's
    // and ReminderSeamTest's subject and not this file's; what is asserted here is that the
    // transition is over by then and that nothing after it writes.
    $after = array_slice($trace, (int) $commit + 1);

    // Where the approval lands is asked under the lock of the tenant's payment connection as
    // its row stands, after the re-read and before the compare-and-set, and of no provider.
    $connection = $transaction[9] ?? '';
    expect($connection)->toStartWith('select provider, mode, state, account_ref')->toContain('payment_connections');

    expect($transaction)->toBe([
        'BEGIN',
        'set local search_path to "tenant_luna", "public"',
        "set local lock_timeout = '3s'",
        "set local statement_timeout = '10s'",
        "select current_setting('transaction_isolation')",
        'select current_schema()',
        'insert into staff_days (staff_id, day, rev) values (?, ?, 0) on conflict (staff_id, day) do update set rev = staff_days.rev + 1 returning staff_id, day, rev',
        PostgresBookingWriter::lockBookingSql(),
        PostgresBookingWriter::readLockedSql(),
        $connection,
        'update bookings set status = ?, updated_at = now(), manage_token_sealed = null, hold_class = ?, hold_expires_at = ?::timestamptz, approved_at = ?::timestamptz, approved_by = ? where id = ? and status = ?',
        AuditRow::sql(),
        'COMMIT',
    ])
        ->and(array_filter($trace, fn (string $statement): bool => $statement === BlockingItems::unblockSql()))->toBe([])
        // Every statement after the commit is a read, which admits the listeners' re-reads and
        // nothing else. Naming the write verbs instead would let through a CTE that writes, a
        // truncate, a merge, or a function call doing any of them, and a BEGIN is not a select
        // either, so a second transaction cannot hide in here.
        ->and(array_values(array_filter($after, fn (string $statement): bool => preg_match('/\A\s*select\b/i', $statement) !== 1)))->toBe([])
        // And there is something there to have filtered. A listener that stopped reading, or
        // one whose failure was swallowed before it read, would satisfy the line above by
        // leaving nothing behind at all.
        ->and($after)->not->toBe([]);
});

it('holds the privacy assertion over an approval: no actor and no payload of the trail names the customer whose request it was', function () {
    $uuid = approveRequested();

    approveUseCase()->approve($uuid, Actor::user(7));

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_NAME)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_PHONE)
        ->and(DB::table('tenant_luna.bookings')->where('uuid', $uuid)->value('customer_email'))->toBe(LunaFixture::CUSTOMER_EMAIL);
});

/**
 * A clock a test moves by hand, for the one case that needs two attempts of one approval to
 * stand at two instants.
 */
final class ApproveRetryClock implements Clock
{
    public function __construct(public DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

/**
 * The approval's pre-read with a gap after its first answer: what the second closure does
 * there happens after the approval has read the staff-days to lock and before it locks any,
 * which is the one moment a reschedule can commit unseen. Every pre-read is one attempt
 * beginning, and the first closure is told of each.
 */
final class ApproveRetryLookup implements BookingLookup
{
    public int $reads = 0;

    public function __construct(
        private BookingLookup $inner,
        private Closure $afterEveryPreRead,
        private Closure $afterTheFirstPreRead,
    ) {}

    public function blocksOf(string $uuid): ?array
    {
        $blocks = $this->inner->blocksOf($uuid);
        ($this->afterEveryPreRead)();

        if (++$this->reads === 1) {
            ($this->afterTheFirstPreRead)();
        }

        return $blocks;
    }
}

it('stamps a retried approval with the clock of the attempt that lands: a reschedule that commits between the first pre-read and its lock moves the key set, the first attempt rolls back at the instant it began at, the clock moves on ninety seconds between the attempts, the approval runs again from step 1, and approved_at is the second attempt\'s instant, one the first attempt never stood at', function () {
    $uuid = approveRequested();
    $tenant = LunaFixture::tenant();
    $factory = app(BookingTransitionFactory::class);
    $clock = new ApproveRetryClock(new DateTimeImmutable(APPROVE_AT, new DateTimeZone('UTC')));

    // Where each attempt begins and ends, and the instant the clock stood at there.
    $timeline = [];
    $mark = function (string $step) use (&$timeline, $clock): void {
        $timeline[] = $step.' at '.$clock->now->format('H:i:s');
    };

    $lookup = new ApproveRetryLookup(
        new PostgresBookingLookup(app(BookingReader::class), $tenant),
        fn () => $mark('pre-read'),
        function () use ($factory, $tenant, $uuid, $clock, $mark): void {
            // The reschedule commits and the clock stays where it is, so the first attempt
            // locks, re-reads and rolls back at the instant its pre-read stood at.
            $factory->reschedule($tenant)->reschedule($uuid, Actor::customer(), LunaFixture::at('2026-11-04 15:00'));

            // Listened for from here on, which keeps the reschedule's own transaction off the
            // timeline: the next transaction to end is the approval's first attempt.
            Event::listen(TransactionRolledBack::class, function () use ($clock, $mark): void {
                $mark('rollback');
                // Between the attempts: the first has rolled back and the second has read nothing.
                $clock->now = $clock->now->add(new DateInterval('PT90S'));
            });
            Event::listen(TransactionCommitted::class, fn () => $mark('commit'));
        },
    );

    $writes = new GuardedWrite(
        $lookup,
        new PostgresBookingRepository(app(TransactionRunner::class), app(LockManager::class), $tenant, app(StaffDayMaskCache::class)),
        $tenant,
        $clock,
        app(TransitionPublishers::class)->for($tenant),
    );

    (new ApproveBooking($writes, new SettlesByProvider(new NullPaymentProvider), fn (): int => 24))->approve($uuid, Actor::user(7));

    $after = LunaFixture::row($uuid);

    // The first attempt stands at 06:00:00 from its pre-read to its rollback and the second at
    // 06:01:30 from its pre-read to its commit, so 06:01:30 is an instant only the second could
    // have read: a stamp taken before the first attempt, or anywhere inside it and carried
    // over, is 06:00:00.
    expect($timeline)->toBe([
        'pre-read at 06:00:00',
        'rollback at 06:00:00',
        'pre-read at 06:01:30',
        'commit at 06:01:30',
    ])
        ->and($lookup->reads)->toBe(2)
        ->and(array_column(LunaFixture::trail(), 'action'))->toBe(['held', 'rescheduled', 'approved'])
        ->and($after)->toMatchArray([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'approved_by' => 7,
            'blocks' => [true],
        ])
        ->and(approveStamp($after['approved_at']))->toBe('2026-11-02T06:01:30.000000+00:00');
});
