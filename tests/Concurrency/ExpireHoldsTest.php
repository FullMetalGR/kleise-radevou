<?php

use App\Application\Checkpoint;
use App\Application\ExpireHolds;
use App\Application\GuardedWrite;
use App\Domain\Audit\Actor;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\PostgresSweepBatch;
use App\Infrastructure\ExpireHoldsFactory;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Harness\ConcurrentRun;

/*
 * Two cases: the sweeper running while a booking transaction holds one of the rows it would have
 * taken, and two sweepers started together.
 *
 * What is on trial in 14 is that the sweeper reads past a locked row rather than waiting for it.
 * SKIP LOCKED is what makes that true, and the observation is twofold: no backend is ever seen
 * blocked inside the selection while the holder stands at its checkpoint, and the sweep's answer
 * arrives while that holder is still standing there, which it could not if it had waited.
 *
 * What is on trial in 15 is the singleton guard. It is the tenant's own key, so two sweeps of
 * one tenant serialise and two sweeps of two tenants do not, although the lock is scoped to the
 * database rather than to the schema and would serialise strangers if the key omitted the
 * tenant.
 *
 * Worker closures run in another process and call only autoloaded code and the framework; nothing
 * declared in this file exists there.
 */

beforeEach(function () {
    foreach (['acme', 'bravo'] as $slug) {
        DB::statement('drop schema if exists tenant_'.$slug.' cascade');
        provisionTenant($slug);
        itemService('tenant_'.$slug);
    }
});

afterEach(function () {
    ConcurrentRun::abandonAll();

    foreach (['acme', 'bravo'] as $slug) {
        DB::statement('drop schema if exists tenant_'.$slug.' cascade');
    }
});

/**
 * Lapsed checkout holds of a tenant, one an hour apart on the implicit staff member so no two
 * blocking items meet on the exclusion constraint. The expiry is the database's own clock, which
 * is the clock the selection compares against.
 *
 * @return list<string> the uuids, oldest booking first
 */
function sweeperLapsed(string $slug, int $count): array
{
    $schema = 'tenant_'.$slug;
    $staffId = implicitStaffId($schema);
    $first = new DateTimeImmutable('2026-11-03 09:00', new DateTimeZone('UTC'));
    $uuids = [];

    for ($index = 0; $index < $count; $index++) {
        $uuid = (string) Str::uuid();
        $from = $first->add(new DateInterval(sprintf('PT%dH', $index)));
        $to = $from->add(new DateInterval('PT45M'));

        $bookingId = DB::table($schema.'.bookings')->insertGetId([
            ...bookingRow(),
            'uuid' => $uuid,
            'status' => 'pending',
            'hold_class' => 'checkout',
            'hold_expires_at' => DB::raw("now() - interval '1 minute'"),
        ]);

        DB::table($schema.'.booking_items')->insert(itemRow(
            $bookingId,
            $staffId,
            $from->format('Y-m-d H:i:sP'),
            $to->format('Y-m-d H:i:sP'),
        ));

        $uuids[] = $uuid;
    }

    return $uuids;
}

/**
 * A worker sweeping one tenant, reporting what the sweep answered.
 */
function sweeperWorker(string $slug, ?string $gate = null): Closure
{
    return function () use ($slug, $gate): array {
        if ($gate !== null) {
            Checkpoint::reach($gate);
        }

        $tenant = Tenant::query()->where('slug', $slug)->sole();
        $swept = app(ExpireHoldsFactory::class)->for($tenant)->sweep();

        return [
            'ran' => $swept->ran,
            'expired' => $swept->expired,
            'confirmed' => $swept->confirmed,
            'batches' => $swept->batches,
        ];
    };
}

/**
 * A worker cancelling one booking of acme, which holds that booking's row from the moment it
 * takes it until the test lets it past the re-read.
 */
function sweeperCancelWorker(string $uuid): Closure
{
    return function () use ($uuid): array {
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        app(BookingTransitionFactory::class)->cancel($tenant)->cancel($uuid, Actor::user(1));

        return ['cancelled' => true];
    };
}

/**
 * The statuses of a tenant's bookings by uuid.
 *
 * @return array<string, string>
 */
function sweeperStatuses(string $slug): array
{
    $statuses = [];

    foreach (DB::select(sprintf('select uuid::text as uuid, status from %s.bookings order by id', 'tenant_'.$slug)) as $row) {
        assert($row instanceof stdClass && is_string($row->uuid) && is_string($row->status));

        $statuses[$row->uuid] = $row->status;
    }

    return $statuses;
}

/**
 * The text a backend inside the sweeper's selection would show in the activity view: the
 * statement up to its first line break, which is the whole of it.
 */
function sweeperSelectionPrefix(): string
{
    return substr(PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH), 0, 60);
}

/**
 * Every wait on a lock seen inside the sweeper's selection while the named worker runs, polled
 * until that worker answers or the bound passes. An empty list is the observation: a selection
 * that skipped rather than waited never appears here, and one that waited would sit in the view
 * for as long as the row it wanted is held.
 *
 * @return list<string>
 */
function sweeperLockWaits(ConcurrentRun $run, string $worker, float $seconds = 5.0): array
{
    $waits = [];
    $deadline = microtime(true) + $seconds;

    while (! $run->hasOutcome($worker) && microtime(true) < $deadline) {
        $row = DB::selectOne(
            "select wait_event from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and query like ?",
            [sweeperSelectionPrefix().'%'],
        );

        if ($row instanceof stdClass && is_string($row->wait_event)) {
            $waits[] = $row->wait_event;
        }

        usleep(2_000);
    }

    return $waits;
}

it('sweeps past the booking a live transaction is holding, expires every other lapsed hold, is never seen waiting on a lock, and blocks that transaction not at all', function () {
    $uuids = sweeperLapsed('acme', 5);
    $held = $uuids[2];

    $run = ConcurrentRun::of([
        'holder' => sweeperCancelWorker($held),
        'sweeper' => sweeperWorker('acme', 'sweep.start'),
    ])->holding(GuardedWrite::AFTER_REREAD, 'sweep.start')->start();
    $run->release();
    // The holder has the staff-day and the booking row, and has not yet asked its guard.
    $run->awaitCheckpoint('holder', GuardedWrite::AFTER_REREAD);
    $run->releaseCheckpoint('sweep.start');

    $waits = sweeperLockWaits($run, 'sweeper');
    $sweptWhileHeld = $run->outcomeOf('sweeper');
    // The decisive observation: the sweep answered while the holder is still standing at its
    // checkpoint, which it could not have done if it had queued behind the row that holder has.
    $holderStillWaiting = ! $run->hasOutcome('holder');

    $run->releaseCheckpoint(GuardedWrite::AFTER_REREAD);
    $outcomes = $run->wait();

    $statuses = sweeperStatuses('acme');
    $trail = DB::table('tenant_acme.audit_log')->orderBy('id')->pluck('action', 'actor')->all();

    expect($waits)->toBe([])
        ->and($holderStillWaiting)->toBeTrue()
        ->and(returnedArray($sweptWhileHeld))->toBe(['ran' => true, 'expired' => 4, 'confirmed' => 0, 'batches' => 1])
        ->and(returnedArray($outcomes['holder']))->toBe(['cancelled' => true])
        ->and($statuses[$held] ?? null)->toBe('cancelled')
        ->and(array_values(array_diff_key($statuses, [$held => true])))->toBe(['expired', 'expired', 'expired', 'expired'])
        // Four rows by the sweeper and one by the user, and the held booking's items let go by
        // the cancel rather than by the sweep.
        ->and($trail)->toBe(['job:sweeper' => 'expired', 'user:1' => 'cancelled'])
        ->and(DB::scalar('select count(*) from tenant_acme.booking_items where blocks'))->toBe(0);
});

it('stands the second sweep of one tenant down at once on the advisory lock, expiring nothing, while the first expires everything', function () {
    sweeperLapsed('acme', 4);

    $run = ConcurrentRun::of([
        'first' => sweeperWorker('acme'),
        'second' => sweeperWorker('acme', 'second.start'),
    ])->holding(ExpireHolds::AFTER_LOCK, 'second.start')->start();
    $run->release();
    // The first sweep holds the tenant's advisory lock and has selected nothing yet.
    $run->awaitCheckpoint('first', ExpireHolds::AFTER_LOCK);
    $run->releaseCheckpoint('second.start');

    $stoodDown = $run->outcomeOf('second');
    $firstStillHolding = ! $run->hasOutcome('first');

    $run->releaseCheckpoint(ExpireHolds::AFTER_LOCK);
    $outcomes = $run->wait();

    expect(returnedArray($stoodDown))->toBe(['ran' => false, 'expired' => 0, 'confirmed' => 0, 'batches' => 0])
        // It answered without waiting: the first has not moved off its checkpoint.
        ->and($firstStillHolding)->toBeTrue()
        ->and(returnedArray($outcomes['first']))->toBe(['ran' => true, 'expired' => 4, 'confirmed' => 0, 'batches' => 1])
        ->and(array_count_values(sweeperStatuses('acme')))->toBe(['expired' => 4])
        ->and(DB::table('tenant_acme.audit_log')->count())->toBe(4);
});

it('ends with one sweep having done all of it and the other none, released together with no checkpoint and the scheduler deciding which is which', function () {
    sweeperLapsed('acme', 4);

    $outcomes = ConcurrentRun::of([
        'one' => sweeperWorker('acme'),
        'two' => sweeperWorker('acme'),
    ])->run();

    $expired = [returnedArray($outcomes['one'])['expired'], returnedArray($outcomes['two'])['expired']];
    sort($expired);

    expect($expired)->toBe([0, 4])
        ->and(array_count_values(sweeperStatuses('acme')))->toBe(['expired' => 4])
        // One audit row per booking, whichever sweep wrote it, and never two.
        ->and(DB::table('tenant_acme.audit_log')->count())->toBe(4);
});

it('sweeps two tenants at once with neither standing down, because the key carries the tenant although the lock is scoped to the database', function () {
    sweeperLapsed('acme', 3);
    sweeperLapsed('bravo', 2);

    $run = ConcurrentRun::of([
        'acme' => sweeperWorker('acme', 'both.start'),
        'bravo' => sweeperWorker('bravo', 'both.start'),
    ])->holding('both.start')->start();
    $run->release();
    // Both are at the gate before either claims, so the two claims genuinely overlap.
    $run->awaitCheckpoint('acme', 'both.start');
    $run->awaitCheckpoint('bravo', 'both.start');
    $run->releaseCheckpoint('both.start');
    $outcomes = $run->wait();

    expect(returnedArray($outcomes['acme']))->toBe(['ran' => true, 'expired' => 3, 'confirmed' => 0, 'batches' => 1])
        ->and(returnedArray($outcomes['bravo']))->toBe(['ran' => true, 'expired' => 2, 'confirmed' => 0, 'batches' => 1])
        ->and(array_count_values(sweeperStatuses('acme')))->toBe(['expired' => 3])
        ->and(array_count_values(sweeperStatuses('bravo')))->toBe(['expired' => 2]);
});
