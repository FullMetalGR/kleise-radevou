<?php

use App\Application\Checkpoint;
use App\Domain\Booking\LockKey;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use App\Domain\Booking\StaffBlock;
use App\Infrastructure\Db\LockManager;
use App\Infrastructure\Db\MutexNotHeld;
use App\Infrastructure\Db\SqlState;
use App\Infrastructure\Db\TransactionRunner;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Concurrency\Observer;

/*
 * The mutex lock, step 3 of the write protocol, on a tenant provisioned for real and dropped
 * afterwards. Worker closures run in another process and call only autoloaded code and the
 * framework.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
});

afterEach(function () {
    // A worker still holding a row lock would make the drop wait forever; the base case abandons
    // runs only after these hooks, so abandon here first.
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_acme cascade');
});

function mutexTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

function lockRunner(): TransactionRunner
{
    return app(TransactionRunner::class);
}

/**
 * The mutex rows of the tenant, revision by staff-day, in lock order.
 *
 * @return array<string, int>
 */
function mutexRows(): array
{
    $rows = [];

    foreach (DB::table('tenant_acme.staff_days')->orderBy('staff_id')->orderBy('day')->get() as $row) {
        assert(is_int($row->staff_id) && is_string($row->day) && is_int($row->rev));

        $rows[$row->staff_id.':'.$row->day] = $row->rev;
    }

    return $rows;
}

/**
 * Mutex rows at revision zero, as an earlier write would have left them.
 *
 * @param  list<array{int, string}>  $pairs
 */
function seedMutexRows(array $pairs): void
{
    DB::table('tenant_acme.staff_days')->insert(array_map(
        fn (array $pair): array => ['staff_id' => $pair[0], 'day' => $pair[1]],
        $pairs,
    ));
}

/**
 * What a closure throws. A closure that throws nothing fails the test.
 */
function thrownBy(Closure $run): Throwable
{
    try {
        $run();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    Assert::fail('Nothing was thrown.');
}

/**
 * The booking a worker writes once it believes it holds the mutex rows.
 *
 * @return array<string, mixed>
 */
function lockedBookingRow(): array
{
    return [
        'uuid' => (string) Str::uuid(),
        'status' => 'confirmed',
        'customer_name' => 'Casey Customer',
        'customer_email' => 'casey@example.com',
        'total_minor' => 2500,
        'currency' => 'EUR',
        'payment_mode' => 'onsite',
        'requires_approval' => false,
        'manage_token_hash' => 'hash',
        'source' => 'embed',
    ];
}

it('creates missing rows at revision zero, bumps existing ones, and returns the post-bump revisions keyed by pair, on the connection it is handed and inside that transaction', function () {
    $manager = new LockManager;
    $keys = LockKeySet::of(new LockKey(2, '2026-10-01'), new LockKey(1, '2026-10-01'));
    $tenant = mutexTenant();
    $observer = Observer::attach();
    DB::enableQueryLog();

    $first = lockRunner()->run($tenant, fn (Connection $db): Revisions => $manager->lock($db, $keys));
    $second = lockRunner()->run($tenant, function (Connection $db) use ($manager, $keys): array {
        return [$manager->lock($db, $keys), $db->transactionLevel(), $db->getPdo()->inTransaction()];
    });
    [$revisions, $level, $inTransaction] = $second;
    $statements = $observer->statements;

    $statement = LockManager::sql(2);
    $prologue = [
        'set local search_path to "tenant_acme", "public"',
        "set local lock_timeout = '3s'",
        "set local statement_timeout = '10s'",
        "select current_setting('transaction_isolation')",
        'select current_schema()',
    ];
    $logged = array_values(array_filter(DB::getQueryLog(), fn (array $entry): bool => $entry['query'] === $statement));

    expect($first->toArray())->toBe(['1:2026-10-01' => 0, '2:2026-10-01' => 0])
        ->and($revisions->toArray())->toBe(['1:2026-10-01' => 1, '2:2026-10-01' => 1])
        ->and($revisions->of(new LockKey(2, '2026-10-01')))->toBe(1)
        ->and($level)->toBe(1)
        ->and($inTransaction)->toBeTrue()
        ->and(mutexRows())->toBe(['1:2026-10-01' => 1, '2:2026-10-01' => 1])
        ->and($statements)->toBe([...$prologue, $statement, ...$prologue, $statement])
        ->and($logged)->toHaveCount(2)
        ->and($logged[0]['bindings'] ?? null)->toBe([1, '2026-10-01', 2, '2026-10-01'])
        ->and(DB::transactionLevel())->toBe(0);
});

it('holds a row lock on every key until the transaction ends, which is what makes the one statement a lock', function () {
    seedMutexRows([[1, '2026-10-01'], [1, '2026-10-02']]);

    $holder = function (): array {
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $keys = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-02'));
        $manager = new LockManager;

        return app(TransactionRunner::class)->run($tenant, function (Connection $db) use ($manager, $keys): array {
            $revisions = $manager->lock($db, $keys)->toArray();
            Checkpoint::reach('held');

            return $revisions;
        });
    };

    $run = ConcurrentRun::of(['holder' => $holder])->holding('held')->start();
    $run->release();
    $run->awaitCheckpoint('holder', 'held');

    $refusals = [];

    foreach (['2026-10-01', '2026-10-02'] as $day) {
        DB::beginTransaction();

        try {
            $refusals[$day] = SqlState::codeOf(thrownBy(fn () => DB::select(
                'select rev from tenant_acme.staff_days where staff_id = 1 and day = ? for update nowait',
                [$day],
            )));
        } finally {
            DB::rollBack();
        }
    }

    $skipped = count(DB::select('select staff_id from tenant_acme.staff_days for update skip locked'));

    $run->releaseCheckpoint('held');
    $outcomes = $run->wait();

    $afterwards = DB::select('select rev from tenant_acme.staff_days where staff_id = 1 for update nowait');

    expect($refusals)->toBe(['2026-10-01' => '55P03', '2026-10-02' => '55P03'])
        ->and($skipped)->toBe(0)
        ->and(returnedArray($outcomes['holder']))->toBe(['1:2026-10-01' => 1, '1:2026-10-02' => 1])
        ->and($afterwards)->toHaveCount(2)
        ->and(mutexRows())->toBe(['1:2026-10-01' => 1, '1:2026-10-02' => 1]);
});

it('issues one VALUES row for a chain with two items on one staff-day, so no 21000 reaches the database (test 13)', function () {
    $zone = new DateTimeZone('Europe/Athens');
    $utc = new DateTimeZone('UTC');
    $keys = LockKeySet::forBlocks([
        new StaffBlock(1, new DateTimeImmutable('2026-10-01 07:00', $utc), new DateTimeImmutable('2026-10-01 08:00', $utc)),
        new StaffBlock(1, new DateTimeImmutable('2026-10-01 09:00', $utc), new DateTimeImmutable('2026-10-01 10:00', $utc)),
    ], $zone);
    $manager = new LockManager;
    $observer = Observer::attach();
    DB::enableQueryLog();

    $revisions = lockRunner()->run(mutexTenant(), fn (Connection $db): Revisions => $manager->lock($db, $keys));

    $lockStatements = array_values(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        fn (string $query): bool => str_starts_with($query, 'insert into staff_days'),
    ));

    expect(count($keys))->toBe(1)
        ->and($lockStatements)->toHaveCount(1)
        ->and(substr_count($lockStatements[0], '(?, ?, 0)'))->toBe(1)
        ->and($lockStatements[0])->toBe(LockManager::sql(1))
        ->and($revisions->toArray())->toBe(['1:2026-10-01' => 0])
        ->and($observer->logs)->toBe([])
        ->and(mutexRows())->toBe(['1:2026-10-01' => 0]);
});

it('lets two workers lock the same two staff-days in sorted order: the second waits on the first, both complete, and each row is bumped twice (test 2)', function () {
    seedMutexRows([[1, '2026-10-01'], [1, '2026-10-02']]);

    $locker = function (string $name): Closure {
        return function () use ($name): array {
            Checkpoint::reach($name.'.start');
            $tenant = Tenant::query()->where('slug', 'acme')->sole();
            $observer = Observer::attach();
            $keys = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-02'));
            $manager = new LockManager;
            $runs = 0;

            $revisions = app(TransactionRunner::class)->run($tenant, function (Connection $db) use ($manager, $keys, $name, &$runs): array {
                $runs++;
                $revisions = $manager->lock($db, $keys)->toArray();
                Checkpoint::reach($name.'.locked');

                return $revisions;
            });

            return [
                'revisions' => $revisions,
                'runs' => $runs,
                'retries' => count($observer->logged('Transaction retried')),
                'lock_statements' => $observer->count('insert into staff_days'),
            ];
        };
    };

    $run = ConcurrentRun::of(['first' => $locker('first'), 'second' => $locker('second')])
        ->holding('second.start', 'first.locked')
        ->start();
    $run->release();
    $run->awaitCheckpoint('first', 'first.locked');
    $run->releaseCheckpoint('second.start');
    $waiting = lockWaiter('insert into staff_days');
    $secondFinishedWhileFirstHeld = $run->hasOutcome('second');
    $run->releaseCheckpoint('first.locked');
    $outcomes = $run->wait();

    $first = returnedArray($outcomes['first']);
    $second = returnedArray($outcomes['second']);

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($secondFinishedWhileFirstHeld)->toBeFalse()
        ->and($first['revisions'] ?? null)->toBe(['1:2026-10-01' => 1, '1:2026-10-02' => 1])
        ->and($second['revisions'] ?? null)->toBe(['1:2026-10-01' => 2, '1:2026-10-02' => 2])
        ->and([$first['runs'] ?? null, $second['runs'] ?? null])->toBe([1, 1])
        ->and([$first['retries'] ?? null, $second['retries'] ?? null])->toBe([0, 0])
        ->and([$first['lock_statements'] ?? null, $second['lock_statements'] ?? null])->toBe([1, 1])
        ->and(mutexRows())->toBe(['1:2026-10-01' => 2, '1:2026-10-02' => 2]);
});

it('deadlocks a worker taking the same two rows in reverse with raw SQL against one going through the sorting type: 40P01 is raised, the runner retries, and the final revisions are consistent (test 3)', function () {
    seedMutexRows([[1, '2026-10-01'], [1, '2026-10-02']]);

    $sorted = function (): array {
        Checkpoint::reach('sorted.start');
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $observer = Observer::attach();
        $keys = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-02'));
        $manager = new LockManager;
        $runs = 0;

        $revisions = app(TransactionRunner::class)->run($tenant, function (Connection $db) use ($manager, $keys, &$runs): array {
            $runs++;

            return $manager->lock($db, $keys)->toArray();
        });

        $retries = $observer->logged('Transaction retried');

        return [
            'revisions' => $revisions,
            'runs' => $runs,
            'retries' => count($retries),
            'retried_sqlstate' => $retries[0]['context']['sqlstate'] ?? null,
        ];
    };

    $reversed = function (): array {
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $observer = Observer::attach();
        $oneRow = 'insert into staff_days (staff_id, day, rev) values (1, ?, 0) on conflict (staff_id, day) do update set rev = staff_days.rev + 1 returning rev';
        $runs = 0;

        $revisions = app(TransactionRunner::class)->run($tenant, function (Connection $db) use ($oneRow, &$runs): array {
            $runs++;
            $later = $db->scalar($oneRow, ['2026-10-02']);
            Checkpoint::reach('reversed.first');
            $earlier = $db->scalar($oneRow, ['2026-10-01']);

            return ['1:2026-10-01' => $earlier, '1:2026-10-02' => $later];
        });

        $retries = $observer->logged('Transaction retried');

        return [
            'revisions' => $revisions,
            'runs' => $runs,
            'retries' => count($retries),
            'retried_sqlstate' => $retries[0]['context']['sqlstate'] ?? null,
        ];
    };

    $run = ConcurrentRun::of(['sorted' => $sorted, 'reversed' => $reversed])
        ->holding('sorted.start', 'reversed.first')
        ->start();
    $run->release();
    $run->awaitCheckpoint('reversed', 'reversed.first');
    $run->releaseCheckpoint('sorted.start');
    $waiting = lockWaiter('insert into staff_days (staff_id, day, rev) values ($1, $2, 0), ($3, $4, 0)');
    $run->releaseCheckpoint('reversed.first');
    $outcomes = $run->wait();

    $a = returnedArray($outcomes['sorted']);
    $b = returnedArray($outcomes['reversed']);
    $victim = ($a['retries'] ?? null) === 1 ? $a : $b;
    $survivor = $victim === $a ? $b : $a;
    $runs = [$a['runs'] ?? null, $b['runs'] ?? null];
    $retries = [$a['retries'] ?? null, $b['retries'] ?? null];
    sort($runs);
    sort($retries);

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($runs)->toBe([1, 2])
        ->and($retries)->toBe([0, 1])
        ->and($victim['retried_sqlstate'] ?? null)->toBe('40P01')
        ->and($survivor['revisions'] ?? null)->toBe(['1:2026-10-01' => 1, '1:2026-10-02' => 1])
        ->and($victim['revisions'] ?? null)->toBe(['1:2026-10-01' => 2, '1:2026-10-02' => 2])
        ->and(mutexRows())->toBe(['1:2026-10-01' => 2, '1:2026-10-02' => 2]);
});

it('raises MutexNotHeld before anything else happens when the lock statement returns fewer rows than the key set has pairs, and the runner rolls back (test 4, the assertion)', function () {
    seedMutexRows([[1, '2026-10-01'], [1, '2026-10-02']]);
    $tenant = mutexTenant();
    $keys = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-02'));
    $manager = new LockManager;
    $observer = Observer::attach();
    $afterLock = false;
    $issuedThroughDouble = null;

    $thrown = thrownBy(function () use ($tenant, $manager, $keys, &$afterLock, &$issuedThroughDouble): void {
        lockRunner()->run($tenant, function (Connection $db) use ($manager, $keys, &$afterLock, &$issuedThroughDouble): void {
            $config = $db->getConfig();
            assert(is_array($config));

            // The runner's own connection and PDO, so the statement runs in its transaction on the
            // tenant schema, but with one row withheld from what the statement returned.
            $short = new class($db->getPdo(), $db->getDatabaseName(), $db->getTablePrefix(), $config) extends PostgresConnection
            {
                /**
                 * @param  string  $query
                 * @param  array<mixed>  $bindings
                 * @param  bool  $useReadPdo
                 * @param  array<mixed>  $fetchUsing
                 * @return array<mixed>
                 */
                public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
                {
                    $rows = parent::select($query, $bindings, $useReadPdo, $fetchUsing);
                    array_pop($rows);

                    return $rows;
                }
            };

            $short->enableQueryLog();

            try {
                $manager->lock($short, $keys);
            } finally {
                $issuedThroughDouble = array_column($short->getQueryLog(), 'query');
            }

            $afterLock = true;
            $db->table('bookings')->insert(lockedBookingRow());
        });
    });

    expect($thrown)->toBeInstanceOf(MutexNotHeld::class)
        ->and($thrown instanceof MutexNotHeld ? $thrown->expected : null)->toBe(2)
        ->and($thrown instanceof MutexNotHeld ? $thrown->returned : null)->toBe(1)
        ->and($thrown instanceof MutexNotHeld ? array_map(fn (LockKey $key): string => $key->toString(), $thrown->missing) : null)->toBe(['1:2026-10-02'])
        ->and($thrown->getMessage())->toContain('returned 1 row for 2 staff-days')->toContain('1:2026-10-02')
        ->and($thrown->getPrevious())->toBeNull()
        ->and($afterLock)->toBeFalse()
        ->and($issuedThroughDouble)->toBe([LockManager::sql(2)])
        ->and($observer->statements)->toBe([
            'set local search_path to "tenant_acme", "public"',
            "set local lock_timeout = '3s'",
            "set local statement_timeout = '10s'",
            "select current_setting('transaction_isolation')",
            'select current_schema()',
        ])
        ->and($observer->logs)->toBe([])
        ->and(DB::table('tenant_acme.bookings')->count())->toBe(0)
        ->and(mutexRows())->toBe(['1:2026-10-01' => 0, '1:2026-10-02' => 0])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::getPdo()->inTransaction())->toBeFalse();
});

it('locks against a mutex row deleted concurrently: on this cluster the statement waits for the deleter and inserts the row afresh, so all rows return and the transaction proceeds (test 4, the cluster)', function () {
    seedMutexRows([[1, '2026-10-01'], [1, '2026-10-02']]);

    $deleter = function (): string {
        return DB::transaction(function (): string {
            DB::table('tenant_acme.staff_days')->where('staff_id', 1)->where('day', '2026-10-01')->delete();
            Checkpoint::reach('deleted');

            return 'deleted';
        });
    };

    $locker = function (): array {
        Checkpoint::reach('locker.start');
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $keys = LockKeySet::of(new LockKey(1, '2026-10-01'), new LockKey(1, '2026-10-02'));
        $manager = new LockManager;

        return app(TransactionRunner::class)->run($tenant, function (Connection $db) use ($manager, $keys): array {
            $revisions = $manager->lock($db, $keys)->toArray();
            $db->table('bookings')->insert([
                'source' => 'embed',
                'uuid' => (string) Str::uuid(),
                'status' => 'confirmed',
                'customer_name' => 'Casey Customer',
                'customer_email' => 'casey@example.com',
                'total_minor' => 2500,
                'currency' => 'EUR',
                'payment_mode' => 'onsite',
                'requires_approval' => false,
                'manage_token_hash' => 'hash',
            ]);

            return $revisions;
        });
    };

    $run = ConcurrentRun::of(['deleter' => $deleter, 'locker' => $locker])
        ->holding('deleted', 'locker.start')
        ->start();
    $run->release();
    $run->awaitCheckpoint('deleter', 'deleted');
    $run->releaseCheckpoint('locker.start');
    $waiting = lockWaiter('insert into staff_days');
    $lockerFinishedWhileDeleterHeld = $run->hasOutcome('locker');
    $run->releaseCheckpoint('deleted');
    $outcomes = $run->wait();

    $locker = $outcomes['locker'];
    $bookings = DB::table('tenant_acme.bookings')->count();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($lockerFinishedWhileDeleterHeld)->toBeFalse()
        ->and($outcomes['deleter']->returned())->toBe('deleted');

    // Either the statement came back short and the assertion fired with nothing written, or the
    // engine re-checked after the deleter committed, inserted the row afresh at revision zero,
    // returned every row, and the transaction went on to write. Both leave the store consistent.
    if ($locker->threw()) {
        expect($locker->exceptionClass)->toBe(MutexNotHeld::class)
            ->and($bookings)->toBe(0)
            ->and(mutexRows())->toBe(['1:2026-10-02' => 0]);
        $behaviour = 'short return';
    } else {
        expect($locker->returned())->toBe(['1:2026-10-01' => 0, '1:2026-10-02' => 1])
            ->and($bookings)->toBe(1)
            ->and(mutexRows())->toBe(['1:2026-10-01' => 0, '1:2026-10-02' => 1]);
        $behaviour = 'fresh insert';
    }

    // Recorded on PostgreSQL 16.15: the fresh insert. A cluster showing the other behaviour is
    // news worth a failing test rather than a silent branch.
    expect($behaviour)->toBe('fresh insert', 'The cluster returned short from the lock statement; the recorded behaviour is the fresh insert. Re-record it here if this is a new engine version.');
});
