<?php

use App\Application\Clock;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingExpired;
use App\Application\ExpireHolds;
use App\Http\BookingPayload;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\BlockingItems;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\PostgresSweepBatch;
use App\Infrastructure\ExpireHoldsFactory;
use App\Jobs\JobTenant;
use App\Jobs\SweepExpiredHolds;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;
use Tests\Support\FixedClock;
use Tests\Support\LunaFixture;

pest()->use(DatabaseTruncation::class);

/*
 * The sweeper on luna's real rows, and the schedule that dispatches it.
 *
 * Not wrapped in a transaction: every batch enters the transaction runner, which refuses to open
 * inside another.
 *
 * luna is in Europe/Athens, UTC+2 in November. 2026-11-03 is a Tuesday, when Elena works 09:00
 * to 13:00 and 14:00 to 18:00. Each seeded hold takes an hour of its own, so two blocking items
 * never meet on the exclusion constraint. Every expiry is set against the database's own clock,
 * because whether a hold has lapsed is the server's comparison inside the selection.
 */

const SWEEP_AT = '2026-11-03 09:00:00';

beforeEach(function () {
    dropDemoSchemas();
    seedDemo();
    app()->instance(Clock::class, FixedClock::at(SWEEP_AT));
});

afterEach(function () {
    DB::purge('sweep_probe');
    dropDemoSchemas();
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

function sweepOf(?Tenant $tenant = null): ExpireHolds
{
    return app(ExpireHoldsFactory::class)->for($tenant ?? LunaFixture::tenant());
}

/**
 * A booking of luna in the state named, on its own hour of the Tuesday so no two blocking items
 * of the fixtures overlap.
 */
function sweepBooking(string $status, ?string $holdClass, string $expiry, int $hour, bool $blocks = true, string $timeout = 'expire'): string
{
    return LunaFixture::booking(
        $status,
        holdClass: $holdClass,
        expiry: $expiry,
        start: sprintf('2026-11-03 %02d:00', $hour),
        minutes: 45,
        blocks: $blocks,
        service: $status === 'awaiting_approval' ? 'Bridal trial' : 'Haircut',
        requiresApproval: $status === 'awaiting_approval',
        onApprovalTimeout: $timeout,
    );
}

/**
 * Every statement one sweep issued, with its bindings, and BEGIN and COMMIT where the
 * transactions opened and closed.
 *
 * @return list<array{string, list<mixed>}>
 */
function sweepTrace(Closure $run): array
{
    $trace = [];
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = [$query->sql, array_values($query->bindings)];
    });
    Event::listen(TransactionBeginning::class, function () use (&$trace): void {
        $trace[] = ['BEGIN', []];
    });
    Event::listen(TransactionCommitted::class, function () use (&$trace): void {
        $trace[] = ['COMMIT', []];
    });

    $run();

    return $trace;
}

/**
 * The statements of a trace, without their bindings.
 *
 * @param  list<array{string, list<mixed>}>  $trace
 * @return list<string>
 */
function sweepStatements(array $trace): array
{
    return array_map(fn (array $entry): string => $entry[0], $trace);
}

/**
 * One booking of luna as the customer holding its manage token is shown it. What the endpoint
 * answers is this array; reading it here keeps the assertion on the payload rather than on the
 * row the payload is built from.
 *
 * @return array<string, mixed>
 */
function sweepGuestView(string $uuid): array
{
    $snapshot = app(BookingReader::class)->snapshot(LunaFixture::tenant(), $uuid)
        ?? throw new LogicException(sprintf('luna has no booking %s.', $uuid));

    return BookingPayload::guest($snapshot);
}

/**
 * How many advisory locks this session holds right now. The sweep's is transaction-scoped, so
 * the answer after a sweep is the answer before it.
 */
function sweepAdvisoryLocks(): int
{
    $held = DB::scalar("select count(*) from pg_locks where locktype = 'advisory' and pid = pg_backend_pid()");

    assert(is_int($held));

    return $held;
}

it('expires the lapsed holds of all three classes, clears the blocking flag on their items, writes one audit row each naming the hold class, and leaves live holds and settled bookings exactly as they were', function () {
    $seen = [];
    Event::listen(BookingExpired::class, function (BookingExpired $event) use (&$seen): void {
        $seen[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => LunaFixture::probe('sweep_probe')->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $checkout = sweepBooking('pending', 'checkout', 'past', 9);
    $approval = sweepBooking('awaiting_approval', 'approval', 'past', 10);
    $payment = sweepBooking('awaiting_payment', 'payment', 'past', 11);
    $live = sweepBooking('pending', 'checkout', 'future', 12);
    $confirmed = sweepBooking('confirmed', null, 'none', 14);
    $cancelled = sweepBooking('cancelled', null, 'none', 15, blocks: false);
    $liveBefore = LunaFixture::row($live);
    $confirmedBefore = LunaFixture::row($confirmed);
    $cancelledBefore = LunaFixture::row($cancelled);

    $swept = sweepOf()->sweep();

    $trail = LunaFixture::trail();

    expect($swept->ran)->toBeTrue()
        ->and($swept->expired)->toBe(3)
        ->and($swept->confirmed)->toBe(0)
        ->and($swept->batches)->toBe(1)
        // The hold ends with the status: every transition out of a held state nulls the class
        // and the expiry, so an expired booking answers the guest view the way a cancelled one
        // does rather than with a class and an expiry already behind it.
        ->and(LunaFixture::row($checkout))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'hold_expires_at' => null, 'blocks' => [false]])
        ->and(LunaFixture::row($approval))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'hold_expires_at' => null, 'blocks' => [false]])
        ->and(LunaFixture::row($payment))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'hold_expires_at' => null, 'blocks' => [false]])
        // Untouched means every column, the expiry and the blocking flag included.
        ->and(LunaFixture::row($live))->toBe($liveBefore)
        ->and(LunaFixture::row($confirmed))->toBe($confirmedBefore)
        ->and(LunaFixture::row($cancelled))->toBe($cancelledBefore)
        ->and($trail)->toHaveCount(3)
        ->and(array_map(fn (array $row): mixed => $row['actor'], $trail))->toBe(['job:sweeper', 'job:sweeper', 'job:sweeper'])
        ->and(array_map(fn (array $row): mixed => $row['action'], $trail))->toBe(['expired', 'expired', 'expired'])
        ->and($trail[0])->toMatchArray(['uuid' => $checkout])
        ->and($trail[0]['payload'])->toBe(['hold_class' => 'checkout', 'status_from' => 'pending', 'status_to' => 'expired'])
        ->and($trail[1])->toMatchArray(['uuid' => $approval])
        ->and($trail[1]['payload'])->toBe(['hold_class' => 'approval', 'status_from' => 'awaiting_approval', 'status_to' => 'expired'])
        ->and($trail[2])->toMatchArray(['uuid' => $payment])
        ->and($trail[2]['payload'])->toBe(['hold_class' => 'payment', 'status_from' => 'awaiting_payment', 'status_to' => 'expired'])
        // One record per booking, after the commit: the row reads expired on another connection
        // by the time the listener sees it, and nothing is open here.
        ->and($seen)->toBe([
            ['uuid' => $checkout, 'from' => 'pending', 'to' => 'expired', 'hold_class' => 'checkout', 'actor' => 'job:sweeper', 'level' => 0, 'committed' => 'expired'],
            ['uuid' => $approval, 'from' => 'awaiting_approval', 'to' => 'expired', 'hold_class' => 'approval', 'actor' => 'job:sweeper', 'level' => 0, 'committed' => 'expired'],
            ['uuid' => $payment, 'from' => 'awaiting_payment', 'to' => 'expired', 'hold_class' => 'payment', 'actor' => 'job:sweeper', 'level' => 0, 'committed' => 'expired'],
        ]);
});

it('confirms a lapsed request whose chain policy auto-approves, with nobody named as the approver and its items still blocking, and expires the same request under expire', function () {
    $approved = [];
    Event::listen(BookingApproved::class, function (BookingApproved $event) use (&$approved): void {
        $approved[] = [
            'uuid' => $event->uuid,
            'from' => $event->from?->value,
            'to' => $event->to->value,
            'hold_class' => $event->holdClass?->value,
            'actor' => $event->actor->value,
            'level' => DB::transactionLevel(),
            'committed' => LunaFixture::probe('sweep_probe')->scalar('select status from tenant_luna.bookings where uuid = ?::uuid', [$event->uuid]),
        ];
    });

    $granted = sweepBooking('awaiting_approval', 'approval', 'past', 9, timeout: 'auto_approve');
    $lapsed = sweepBooking('awaiting_approval', 'approval', 'past', 11, timeout: 'expire');

    $swept = sweepOf()->sweep();

    $trail = LunaFixture::trail();
    $granted_ = LunaFixture::row($granted);

    expect($swept->expired)->toBe(1)
        ->and($swept->confirmed)->toBe(1)
        ->and($granted_)->toMatchArray([
            'status' => 'confirmed',
            'hold_class' => null,
            'hold_expires_at' => null,
            'approved_by' => null,
            'blocks' => [true],
        ])
        ->and($granted_['approved_at'])->toBeString()
        ->and(LunaFixture::row($lapsed))->toMatchArray(['status' => 'expired', 'hold_class' => null, 'hold_expires_at' => null, 'blocks' => [false]])
        ->and($trail)->toHaveCount(2)
        // The holds it let go are written first and the requests it granted after, which is the
        // order the two settlements run in.
        ->and($trail[0])->toMatchArray(['uuid' => $lapsed, 'action' => 'expired', 'actor' => 'job:sweeper'])
        ->and($trail[0]['payload'])->toBe(['hold_class' => 'approval', 'status_from' => 'awaiting_approval', 'status_to' => 'expired'])
        ->and($trail[1])->toMatchArray(['uuid' => $granted, 'action' => 'approved', 'actor' => 'job:sweeper'])
        ->and($trail[1]['payload'])->toBe(['hold_class' => 'approval', 'status_from' => 'awaiting_approval', 'status_to' => 'confirmed'])
        ->and($approved)->toBe([[
            'uuid' => $granted,
            'from' => 'awaiting_approval',
            'to' => 'confirmed',
            'hold_class' => 'approval',
            'actor' => 'job:sweeper',
            'level' => 0,
            'committed' => 'confirmed',
        ]]);
});

it('answers the guest view of a booking it expired the way a cancelled one is answered, with no hold class and no expiry, which is the reason the expiry ends the hold at all', function () {
    $swept = sweepBooking('pending', 'checkout', 'past', 9);
    $cancelled = sweepBooking('cancelled', null, 'none', 11, blocks: false);
    $before = sweepGuestView($swept);

    sweepOf()->sweep();

    $after = sweepGuestView($swept);
    $settled = sweepGuestView($cancelled);

    // Before the sweep the row is a live hold's row and says so, which is what the countdown
    // reads; after it the booking is settled and the two settled statuses answer alike.
    expect($before['hold_class'])->toBe('checkout')
        ->and($before['hold_expires_at'])->toBeString()
        ->and($after)->toMatchArray(['status' => 'expired', 'hold_class' => null, 'hold_expires_at' => null])
        ->and($settled)->toMatchArray(['status' => 'cancelled', 'hold_class' => null, 'hold_expires_at' => null]);
});

it('stamps the confirm with the clock the use case was handed, as the owner\'s own Approve does', function () {
    sweepBooking('awaiting_approval', 'approval', 'past', 9, timeout: 'auto_approve');

    sweepOf()->sweep();

    $stamp = DB::scalar("select to_char(approved_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') from tenant_luna.bookings where approved_at is not null");

    expect($stamp)->toBe(SWEEP_AT);
});

it('issues, for one batch, the advisory lock first after the prologue, the selection with SKIP LOCKED and a limit of five hundred, one settlement statement, the unblock and the trail, one transaction per batch and nothing touching staff_days', function () {
    sweepBooking('pending', 'checkout', 'past', 9);
    sweepBooking('pending', 'checkout', 'past', 10);
    // Built before the trace starts, so what it records is the sweep and not the tenant read
    // every caller does before it has a use case at all.
    $sweeps = sweepOf();
    $tenantId = LunaFixture::tenant()->id;

    $trace = sweepTrace(fn () => $sweeps->sweep());
    $statements = sweepStatements($trace);
    $first = array_slice($statements, (int) array_search('BEGIN', $statements, true));

    expect(array_slice($first, 0, 11))->toBe([
        'BEGIN',
        'set local search_path to "tenant_luna", "public"',
        "set local lock_timeout = '3s'",
        "set local statement_timeout = '10s'",
        "select current_setting('transaction_isolation')",
        'select current_schema()',
        PostgresSweepBatch::claimSql(),
        PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH),
        PostgresSweepBatch::expireSql(),
        BlockingItems::unblockSql(),
        // One trail statement for the two bookings, never one apiece.
        AuditRow::batchSql(2),
    ])
        ->and($first[11] ?? null)->toBe('COMMIT')
        // The key is two plain integers: the fixed class and this tenant's central id.
        ->and($trace[(int) array_search(PostgresSweepBatch::claimSql(), $statements, true)][1])->toBe([PostgresSweepBatch::LOCK_CLASS, $tenantId])
        ->and(PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH))->toContain('skip locked')
        ->and(PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH))->toContain('limit 500')
        // One transaction per batch: the batch that settled two holds, and the one that found
        // nothing and ended the sweep.
        ->and(count(array_filter($statements, fn (string $sql): bool => $sql === 'BEGIN')))->toBe(2)
        ->and(count(array_filter($statements, fn (string $sql): bool => $sql === 'COMMIT')))->toBe(2)
        ->and(count(array_filter($statements, fn (string $sql): bool => $sql === PostgresSweepBatch::claimSql())))->toBe(2)
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'staff_days')))->toBe([]);
});

it('runs three batches over twelve hundred lapsed holds, five hundred, five hundred and two hundred, and one more transaction that finds nothing and ends the sweep', function () {
    DB::statement(
        'insert into tenant_luna.bookings (uuid, status, hold_class, hold_expires_at, customer_name, customer_email,'
        .' total_minor, currency, payment_mode, requires_approval, manage_token_hash, source)'
        ." select gen_random_uuid(), 'pending', 'checkout', now() - interval '1 minute', 'Bulk Holder', 'bulk@example.test',"
        ." 2500, 'EUR', 'onsite', false, 'hash', 'embed' from generate_series(1, 1200)"
    );
    $sweeps = sweepOf();

    $trace = sweepTrace(fn () => $sweeps->sweep());
    $statements = sweepStatements($trace);
    $selections = array_values(array_filter($statements, fn (string $sql): bool => $sql === PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH)));
    $settlements = array_values(array_filter($statements, fn (string $sql): bool => $sql === PostgresSweepBatch::expireSql()));

    expect(DB::scalar("select count(*) from tenant_luna.bookings where status = 'expired'"))->toBe(1200)
        ->and(DB::scalar('select count(*) from tenant_luna.audit_log'))->toBe(1200)
        ->and($selections)->toHaveCount(4)
        ->and($settlements)->toHaveCount(3)
        ->and(count(array_filter($statements, fn (string $sql): bool => $sql === 'BEGIN')))->toBe(4);
})->group('slow');

it('counts three batches and twelve hundred holds in what it answers', function () {
    DB::statement(
        'insert into tenant_luna.bookings (uuid, status, hold_class, hold_expires_at, customer_name, customer_email,'
        .' total_minor, currency, payment_mode, requires_approval, manage_token_hash, source)'
        ." select gen_random_uuid(), 'pending', 'checkout', now() - interval '1 minute', 'Bulk Holder', 'bulk@example.test',"
        ." 2500, 'EUR', 'onsite', false, 'hash', 'embed' from generate_series(1, 1200)"
    );

    $swept = sweepOf()->sweep();

    expect($swept->ran)->toBeTrue()
        ->and($swept->expired)->toBe(1200)
        ->and($swept->confirmed)->toBe(0)
        ->and($swept->batches)->toBe(3);
})->group('slow');

it('holds no advisory lock once the sweep has returned, because the lock it takes is transaction-scoped', function () {
    sweepBooking('pending', 'checkout', 'past', 9);
    $before = sweepAdvisoryLocks();

    sweepOf()->sweep();

    expect($before)->toBe(0)
        ->and(sweepAdvisoryLocks())->toBe(0);
});

it('does nothing on a tenant with nothing to sweep, and nothing at all on a tenant with no bookings', function () {
    sweepBooking('confirmed', null, 'none', 9);
    sweepBooking('pending', 'checkout', 'future', 11);

    $swept = sweepOf()->sweep();
    $other = sweepOf(Tenant::query()->where('slug', 'pier')->sole())->sweep();

    expect($swept->ran)->toBeTrue()
        ->and($swept->touched())->toBe(0)
        ->and($other->touched())->toBe(0)
        ->and(LunaFixture::trail())->toBe([])
        ->and(DB::scalar('select count(*) from tenant_pier.audit_log'))->toBe(0);
});

it('sweeps one tenant and never another, although the two share a database and the lock is a database-wide one', function () {
    $mine = sweepBooking('pending', 'checkout', 'past', 9);
    DB::statement(
        'insert into tenant_pier.bookings (uuid, status, hold_class, hold_expires_at, customer_name, customer_email,'
        .' total_minor, currency, payment_mode, requires_approval, manage_token_hash, source)'
        ." values (gen_random_uuid(), 'pending', 'checkout', now() - interval '1 minute', 'Other Holder', 'other@example.test',"
        ." 2500, 'GBP', 'onsite', false, 'hash', 'embed')"
    );

    $swept = sweepOf()->sweep();

    expect($swept->expired)->toBe(1)
        ->and(LunaFixture::row($mine))->toMatchArray(['status' => 'expired'])
        ->and(DB::scalar('select status from tenant_pier.bookings'))->toBe('pending')
        ->and(DB::scalar('select count(*) from tenant_pier.audit_log'))->toBe(0);
});

it('holds the privacy assertion over a sweep: no actor and no payload of the trail names the customer whose hold it was', function () {
    sweepBooking('pending', 'checkout', 'past', 9);
    sweepBooking('awaiting_approval', 'approval', 'past', 11, timeout: 'auto_approve');

    sweepOf()->sweep();

    $trail = DB::scalar("select string_agg(actor || ' ' || action || ' ' || payload::text, ' ') from tenant_luna.audit_log");

    expect(auditOffences())->toBe([])
        ->and($trail)->toBeString()
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_NAME)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_EMAIL)
        ->and($trail)->not->toContain(LunaFixture::CUSTOMER_PHONE);
});

it('is scheduled every minute and dispatches one queued sweep per tenant whose schema exists, which is every status but provisioning', function () {
    Queue::fake();
    DB::table('tenants')->where('slug', 'pier')->update(['status' => TenantStatus::Suspended->value]);
    $held = Tenant::query()->create([
        'slug' => 'hold',
        'name' => 'A signup in flight',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
    ]);
    $held->status = TenantStatus::Provisioning;
    $held->save();

    Artisan::call('holds:sweep');

    $scheduled = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (object $event): bool => is_string($event->command ?? null) && str_contains($event->command, 'holds:sweep'),
    ));

    expect($scheduled)->toHaveCount(1)
        ->and($scheduled[0]->expression)->toBe('* * * * *')
        ->and(Artisan::output())->toContain('Queued 2 sweeps.');

    Queue::assertPushed(SweepExpiredHolds::class, 2);
    Queue::assertPushed(SweepExpiredHolds::class, fn (SweepExpiredHolds $job): bool => $job->tenantId === LunaFixture::tenant()->id);
    Queue::assertNotPushed(SweepExpiredHolds::class, fn (SweepExpiredHolds $job): bool => $job->tenantId === $held->id);
});

it('dispatches no sweep for a closed row, whose schema the drop removed', function () {
    Queue::fake();
    $closed = writeClosed('pier');

    Artisan::call('holds:sweep');

    expect(Artisan::output())->toContain('Queued 1 sweep.');
    Queue::assertPushed(SweepExpiredHolds::class, 1);
    Queue::assertNotPushed(SweepExpiredHolds::class, fn (SweepExpiredHolds $job): bool => $job->tenantId === $closed);
});

it('logs the tenant whose sweep threw and leaves the other tenants\' sweeps to run, because each tenant has a job of its own', function () {
    $logs = [];
    Log::listen(function (MessageLogged $entry) use (&$logs): void {
        $logs[] = [$entry->level, $entry->message, $entry->context];
    });

    $uuid = sweepBooking('pending', 'checkout', 'past', 9);
    // A tenant row whose schema is not there: the runner's own current_schema() assertion is
    // what fails, which is the shape a dropped or half-provisioned schema arrives in.
    $broken = Tenant::query()->create([
        'slug' => 'gone',
        'name' => 'A tenant with no schema',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
    ]);

    app(SweepExpiredHolds::class, ['tenantId' => $broken->id])->handle(app(ExpireHoldsFactory::class), app(LoggerInterface::class));
    app(SweepExpiredHolds::class, ['tenantId' => LunaFixture::tenant()->id])->handle(app(ExpireHoldsFactory::class), app(LoggerInterface::class));

    $failures = array_values(array_filter($logs, fn (array $entry): bool => $entry[1] === 'The hold sweep failed'));
    $swept = array_values(array_filter($logs, fn (array $entry): bool => $entry[1] === 'Holds swept'));

    expect($failures)->toHaveCount(1)
        ->and($failures[0][0])->toBe('error')
        ->and($failures[0][2]['tenant'] ?? null)->toBe('gone')
        ->and($swept)->toHaveCount(1)
        ->and($swept[0][2])->toMatchArray(['tenant' => 'luna', 'expired' => 1, 'confirmed' => 0, 'batches' => 1])
        ->and(LunaFixture::row($uuid))->toMatchArray(['status' => 'expired']);
});

it('writes nothing for a tenant whose row went away or was closed between the dispatch and the run, saying so once at info', function () {
    $logs = [];
    Log::listen(function (MessageLogged $entry) use (&$logs): void {
        $logs[] = [$entry->level, $entry->message, $entry->context];
    });
    $closed = writeClosed('pier');

    app(SweepExpiredHolds::class, ['tenantId' => 0])->handle(app(ExpireHoldsFactory::class), app(LoggerInterface::class));
    app(SweepExpiredHolds::class, ['tenantId' => $closed])->handle(app(ExpireHoldsFactory::class), app(LoggerInterface::class));

    expect($logs)->toBe([
        ['info', JobTenant::GONE, ['tenant' => 0, 'job' => SweepExpiredHolds::class]],
        ['info', JobTenant::CLOSED, ['tenant' => $closed, 'job' => SweepExpiredHolds::class]],
    ]);
});
