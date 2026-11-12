<?php

use App\Application\Checkpoint;
use App\Infrastructure\Db\AbortedTransactionReused;
use App\Infrastructure\Db\BudgetExceeded;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\CardinalityViolation;
use App\Infrastructure\Db\ExclusionViolation;
use App\Infrastructure\Db\PreconditionFailed;
use App\Infrastructure\Db\RetriesExhausted;
use App\Infrastructure\Db\SqlState;
use App\Infrastructure\Db\TransactionFailure;
use App\Infrastructure\Db\TransactionRunner;
use App\Infrastructure\Db\UniqueViolation;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Concurrency\Observer;

/*
 * The transaction runner, on a tenant provisioned for real and dropped afterwards. Worker
 * closures run in another process and call only autoloaded code and the framework; the
 * Observer is autoloaded for that reason.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::statement('drop schema if exists tenant_bravo cascade');
    provisionTenant('acme');
    itemService('tenant_acme');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::statement('drop schema if exists tenant_bravo cascade');
});

function acmeTenant(): Tenant
{
    return Tenant::query()->where('slug', 'acme')->sole();
}

function runner(): TransactionRunner
{
    return app(TransactionRunner::class);
}

/**
 * What a closure throws. A closure that throws nothing fails the test.
 */
function caught(Closure $run): Throwable
{
    try {
        $run();
    } catch (Throwable $thrown) {
        return $thrown;
    }

    Assert::fail('Nothing was thrown.');
}

/**
 * A locale the cluster translates its messages into, or null when it offers none. Each
 * candidate is asked for a division by zero: a locale the OS lacks is refused outright, one
 * without a message catalog answers in English, and one that translates is the one wanted.
 */
function translatedLocale(): ?string
{
    foreach (['de_DE.UTF-8', 'de_DE.utf8', 'fr_FR.UTF-8', 'fr_FR.utf8', 'es_ES.UTF-8', 'es_ES.utf8'] as $locale) {
        DB::beginTransaction();

        try {
            DB::unprepared(sprintf("set local lc_messages = '%s'", $locale));
            DB::select('select 1/0');
        } catch (QueryException $e) {
            if (SqlState::codeOf($e) === '22012' && ! str_contains($e->getMessage(), 'division by zero')) {
                return $locale;
            }
        } finally {
            DB::rollBack();
        }
    }

    return null;
}

it('refuses to start inside an open transaction, before issuing any SQL (test 11)', function () {
    $tenant = acmeTenant();
    DB::beginTransaction();
    $observer = Observer::attach();
    $ran = false;

    try {
        $failure = caught(function () use ($tenant, &$ran): void {
            runner()->run($tenant, function () use (&$ran): void {
                $ran = true;
            });
        });

        expect($failure)->toBeInstanceOf(PreconditionFailed::class)
            ->and($failure instanceof PreconditionFailed ? $failure->precondition : null)->toBe('P1')
            ->and($failure->getMessage())->toContain('1 deep')
            ->and($observer->statements)->toBe([])
            ->and($ran)->toBeFalse()
            ->and(DB::transactionLevel())->toBe(1);
    } finally {
        DB::rollBack();
    }
});

it('opens the transaction with the three SET LOCAL statements in their fixed order, then asserts P4 and P5, then runs the closure', function () {
    $tenant = acmeTenant();
    DB::enableQueryLog();

    runner()->run($tenant, fn (Connection $db): array => $db->select('select 1 as one'));

    expect(array_column(DB::getQueryLog(), 'query'))->toBe([
        'set local search_path to "tenant_acme", "public"',
        "set local lock_timeout = '3s'",
        "set local statement_timeout = '10s'",
        "select current_setting('transaction_isolation')",
        'select current_schema()',
        'select 1 as one',
    ]);
});

it('runs the closure on the tenant schema with the timeouts set, commits its writes and returns its result', function () {
    $result = runner()->run(acmeTenant(), function (Connection $db): array {
        $db->insert('insert into staff_days (staff_id, day) values (1, ?)', ['2026-10-01']);

        return [
            'level' => $db->transactionLevel(),
            'schema' => $db->scalar('select current_schema()'),
            'lock_timeout' => $db->scalar("select current_setting('lock_timeout')"),
            'statement_timeout' => $db->scalar("select current_setting('statement_timeout')"),
        ];
    });

    expect($result)->toBe(['level' => 1, 'schema' => 'tenant_acme', 'lock_timeout' => '3s', 'statement_timeout' => '10s'])
        ->and(DB::table('tenant_acme.staff_days')->where('staff_id', 1)->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});

it('leaves no SET LOCAL setting behind after commit', function () {
    runner()->run(acmeTenant(), fn (Connection $db): array => $db->select('select 1'));

    expect(DB::scalar("select current_setting('lock_timeout')"))->toBe('0')
        ->and(DB::scalar("select current_setting('statement_timeout')"))->toBe('0')
        ->and(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});

it('asserts READ COMMITTED inside the transaction, before the closure runs (test 5)', function () {
    $tenant = acmeTenant();
    DB::unprepared("set default_transaction_isolation to 'repeatable read'");
    $ran = false;

    try {
        $failure = caught(function () use ($tenant, &$ran): void {
            runner()->run($tenant, function () use (&$ran): void {
                $ran = true;
            });
        });

        expect($failure)->toBeInstanceOf(PreconditionFailed::class)
            ->and($failure instanceof PreconditionFailed ? $failure->precondition : null)->toBe('P4')
            ->and($failure->getMessage())->toContain('"repeatable read"')
            ->and($ran)->toBeFalse()
            ->and(DB::transactionLevel())->toBe(0)
            ->and(DB::getPdo()->inTransaction())->toBeFalse();
    } finally {
        DB::unprepared('reset default_transaction_isolation');
    }
});

it('asserts current_schema() is the tenant schema: a tenant whose schema is missing trips it instead of landing on the central schema', function () {
    $ghost = new Tenant(['slug' => 'ghost', 'name' => 'Ghost', 'timezone' => 'UTC', 'currency' => 'EUR']);
    DB::unprepared('set search_path to "tenant_acme", "public"');
    $ran = false;

    try {
        $failure = caught(function () use ($ghost, &$ran): void {
            runner()->run($ghost, function () use (&$ran): void {
                $ran = true;
            });
        });

        expect($failure)->toBeInstanceOf(PreconditionFailed::class)
            ->and($failure instanceof PreconditionFailed ? $failure->precondition : null)->toBe('P5')
            ->and($failure->getMessage())->toContain('"tenant_ghost"')->toContain('"public"')
            ->and($ran)->toBeFalse()
            ->and(DB::transactionLevel())->toBe(0);
    } finally {
        DB::unprepared('reset search_path');
    }
});

it('overrides a session search path carrying another tenant for the transaction, and hands it back after commit', function () {
    $bravo = provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com'])->tenant;
    DB::unprepared('set search_path to "tenant_acme", "public"');

    try {
        $inside = runner()->run($bravo, fn (Connection $db): mixed => $db->scalar('select current_schema()'));

        expect($inside)->toBe('tenant_bravo')
            ->and(DB::scalar('select current_schema()'))->toBe('tenant_acme');
    } finally {
        DB::unprepared('reset search_path');
    }
});

it('rolls back and rethrows a closure exception of its own untouched', function () {
    $failure = caught(fn () => runner()->run(acmeTenant(), function (Connection $db): void {
        $db->insert('insert into staff_days (staff_id, day) values (1, ?)', ['2026-10-01']);

        throw new DomainException('the booking is invalid');
    }));

    expect($failure)->toBeInstanceOf(DomainException::class)
        ->and($failure->getMessage())->toBe('the booking is invalid')
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0);
});

it('maps a live {sqlstate} to {failure}: no retry, rolled back, reported at {level}, connection usable', function (string $sqlstate, string $failure, string $level, Closure $provoke) {
    $tenant = acmeTenant();
    $observer = Observer::attach();
    $runs = 0;

    $thrown = caught(function () use ($tenant, &$runs, $provoke): void {
        runner()->run($tenant, function (Connection $db) use (&$runs, $provoke): void {
            $runs++;
            $db->insert('insert into staff_days (staff_id, day) values (7, ?)', ['2026-12-31']);
            $provoke($db);
        });
    });

    $reports = $observer->atLevel($level);

    expect($thrown::class)->toBe($failure)
        ->and($thrown instanceof TransactionFailure ? $thrown->sqlstate->value : null)->toBe($sqlstate)
        ->and($thrown instanceof TransactionFailure ? $thrown->tenant : null)->toBe('acme')
        ->and($runs)->toBe(1)
        ->and(DB::table('tenant_acme.staff_days')->where('staff_id', 7)->exists())->toBeFalse()
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::getPdo()->inTransaction())->toBeFalse()
        ->and(DB::scalar('select 1'))->toBe(1)
        ->and($reports)->toHaveCount(1)
        ->and($reports[0]['message'] ?? null)->toBe($thrown->getMessage())
        ->and($reports[0]['context']['sqlstate'] ?? null)->toBe($sqlstate)
        ->and($reports[0]['context']['tenant'] ?? null)->toBe('acme')
        ->and($reports[0]['context']['failure'] ?? null)->toBe($failure);
})->with([
    '57014, the budget blown' => ['57014', BudgetExceeded::class, 'critical', function (Connection $db): void {
        $db->unprepared("set local statement_timeout = '100ms'");
        $db->select('select pg_sleep(1)');
    }],
    '23P01, the exclusion constraint' => ['23P01', ExclusionViolation::class, 'alert', function (Connection $db): void {
        $bookingId = $db->table('bookings')->insertGetId(bookingRow());
        $db->table('booking_items')->insert(itemRow($bookingId, 1, '2026-10-01 09:00+00', '2026-10-01 10:00+00'));
        $db->table('booking_items')->insert(itemRow($bookingId, 1, '2026-10-01 09:30+00', '2026-10-01 10:30+00'));
    }],
    '23505, a unique key' => ['23505', UniqueViolation::class, 'alert', function (Connection $db): void {
        $db->insert('insert into staff_days (staff_id, day) values (1, ?)', ['2026-10-01']);
        $db->insert('insert into staff_days (staff_id, day) values (1, ?)', ['2026-10-01']);
    }],
    '21000, a duplicate pair in one VALUES list' => ['21000', CardinalityViolation::class, 'error', function (Connection $db): void {
        $db->select(
            'insert into staff_days (staff_id, day, rev) values (1, ?, 0), (1, ?, 0) on conflict (staff_id, day) do update set rev = staff_days.rev + 1 returning rev',
            ['2026-10-01', '2026-10-01'],
        );
    }],
    '25P02, code that continued after an error' => ['25P02', AbortedTransactionReused::class, 'error', function (Connection $db): void {
        try {
            $db->select('select 1/0');
        } catch (QueryException) {
            // A handler ported from an engine where a statement error leaves the transaction alive.
        }

        $db->select('select 1');
    }],
]);

it('never issues a statement into an aborted transaction, which the engine refuses with 25P02 (test 9)', function () {
    DB::beginTransaction();

    try {
        expect(fn () => DB::select('select 1/0'))->toThrow(QueryException::class, 'SQLSTATE[22012]');
        expect(fn () => DB::select('select 1'))->toThrow(QueryException::class, 'SQLSTATE[25P02]');
    } finally {
        DB::rollBack();
    }

    $tenant = acmeTenant();
    $observer = Observer::attach();

    $thrown = caught(fn () => runner()->run($tenant, function (Connection $db): void {
        $db->select('select 1/0');
    }));

    expect($thrown)->toBeInstanceOf(QueryException::class)
        ->and(SqlState::codeOf($thrown))->toBe('22012')
        ->and($observer->lastStatement())->toBe('select 1/0')
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::getPdo()->inTransaction())->toBeFalse()
        ->and(DB::scalar('select 1'))->toBe(1);
});

it('retries a concurrency failure at most three times, re-running the whole closure, then surfaces it as exhausted', function () {
    $tenant = acmeTenant();
    $observer = Observer::attach();
    $runs = 0;

    $thrown = caught(function () use ($tenant, &$runs): void {
        runner()->run($tenant, function (Connection $db) use (&$runs): void {
            $runs++;
            $db->insert('insert into staff_days (staff_id, day) values (1, ?)', ['2026-10-01']);

            throw sqlError('40001');
        });
    });

    $retries = $observer->logged('Transaction retried');

    expect($thrown)->toBeInstanceOf(RetriesExhausted::class)
        ->and($thrown instanceof RetriesExhausted ? $thrown->attempts : null)->toBe(3)
        ->and($runs)->toBe(3)
        ->and($observer->count('set local search_path'))->toBe(3)
        ->and(DB::table('tenant_acme.staff_days')->count())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and($retries)->toHaveCount(2)
        ->and(array_column(array_column($retries, 'context'), 'attempt'))->toBe([1, 2])
        ->and($observer->atLevel('warning'))->toHaveCount(1);
});

it('maps a lock timeout to busy: not retried, rolled back fully, counted, connection still usable (test 8)', function () {
    DB::table('tenant_acme.staff_days')->insert(['staff_id' => 1, 'day' => '2026-10-01']);

    $holder = function (): string {
        return DB::transaction(function (): string {
            DB::table('tenant_acme.staff_days')->where('staff_id', 1)->where('day', '2026-10-01')->lockForUpdate()->get();
            Checkpoint::reach('holding');

            return 'held';
        });
    };
    $waiter = function (): array {
        Checkpoint::reach('go');
        $tenant = Tenant::query()->where('slug', 'acme')->sole();
        $observer = Observer::attach();
        $runs = 0;

        try {
            app(TransactionRunner::class)->run($tenant, function (Connection $db) use (&$runs): void {
                $runs++;
                $db->insert('insert into staff_days (staff_id, day) values (7, ?)', ['2026-12-31']);
                $db->select('select rev from staff_days where staff_id = 1 and day = ? for update', ['2026-10-01']);
            });
        } catch (Busy $busy) {
            $lastStatement = $observer->lastStatement();
            $counted = $observer->atLevel('notice');

            return [
                'failure' => $busy::class,
                'sqlstate' => $busy->sqlstate->value,
                'runs' => $runs,
                'last_statement' => $lastStatement,
                'level' => DB::transactionLevel(),
                'in_transaction' => DB::getPdo()->inTransaction(),
                'lock_timeout_after' => DB::scalar("select current_setting('lock_timeout')"),
                'usable' => DB::scalar('select 1'),
                'counted' => count($counted),
                'counted_sqlstate' => $counted[0]['context']['sqlstate'] ?? null,
                'counted_tenant' => $counted[0]['context']['tenant'] ?? null,
            ];
        }

        return ['failure' => null];
    };

    $run = ConcurrentRun::of(['holder' => $holder, 'waiter' => $waiter])->holding('holding', 'go')->start();
    $run->release();
    $run->awaitCheckpoint('holder', 'holding');
    $run->releaseCheckpoint('go');
    $report = returnedArray($run->outcomeOf('waiter'));
    $run->releaseCheckpoint('holding');
    $outcomes = $run->wait();

    expect($report['failure'] ?? null)->toBe(Busy::class)
        ->and($report['sqlstate'] ?? null)->toBe('55P03')
        ->and($report['runs'] ?? null)->toBe(1)
        ->and($report['last_statement'] ?? null)->toBe('select rev from staff_days where staff_id = 1 and day = ? for update')
        ->and($report['level'] ?? null)->toBe(0)
        ->and($report['in_transaction'] ?? null)->toBeFalse()
        ->and($report['lock_timeout_after'] ?? null)->toBe('0')
        ->and($report['usable'] ?? null)->toBe(1)
        ->and($report['counted'] ?? null)->toBe(1)
        ->and($report['counted_sqlstate'] ?? null)->toBe('55P03')
        ->and($report['counted_tenant'] ?? null)->toBe('acme')
        ->and($outcomes['holder']->returned())->toBe('held')
        ->and(DB::table('tenant_acme.staff_days')->where('staff_id', 7)->exists())->toBeFalse();
});

it('retries a forced deadlock by SQLSTATE, re-running the whole closure, and both workers finish (test 12)', function () {
    DB::table('tenant_acme.staff_days')->insert([
        ['staff_id' => 1, 'day' => '2026-10-01'],
        ['staff_id' => 1, 'day' => '2026-10-02'],
    ]);

    $locker = function (string $first, string $second, string $checkpoint, ?string $locale): Closure {
        return function () use ($first, $second, $checkpoint, $locale): array {
            $division = 'not asked';

            if ($locale !== null) {
                DB::statement(sprintf("set lc_messages = '%s'", $locale));

                try {
                    DB::select('select 1/0');
                } catch (QueryException $e) {
                    $division = $e->getMessage();
                }
            }

            $tenant = Tenant::query()->where('slug', 'acme')->sole();
            $observer = Observer::attach();
            $runs = 0;

            $locked = app(TransactionRunner::class)->run($tenant, function (Connection $db) use ($first, $second, $checkpoint, &$runs): mixed {
                $runs++;
                $db->select('select rev from staff_days where staff_id = 1 and day = ? for update', [$first]);
                Checkpoint::reach($checkpoint);
                $db->select('select rev from staff_days where staff_id = 1 and day = ? for update', [$second]);

                return $db->scalar('select count(*) from staff_days');
            });

            $retries = $observer->logged('Transaction retried');

            return [
                'locked' => $locked,
                'runs' => $runs,
                'entered' => $observer->count('set local search_path'),
                'retries' => count($retries),
                'retried_sqlstate' => $retries[0]['context']['sqlstate'] ?? null,
                'division' => $division,
            ];
        };
    };

    $locales = [null];
    $translated = translatedLocale();

    if ($translated !== null) {
        $locales[] = $translated;
    }

    foreach ($locales as $locale) {
        $run = ConcurrentRun::of([
            'a' => $locker('2026-10-01', '2026-10-02', 'a.first', $locale),
            'b' => $locker('2026-10-02', '2026-10-01', 'b.first', $locale),
        ])->holding('a.first', 'b.first')->start();
        $run->release();
        $run->awaitCheckpoint('a', 'a.first');
        $run->awaitCheckpoint('b', 'b.first');
        $run->releaseCheckpoint('a.first');
        $run->releaseCheckpoint('b.first');
        $outcomes = $run->wait();

        $a = returnedArray($outcomes['a']);
        $b = returnedArray($outcomes['b']);
        $runs = [$a['runs'] ?? null, $b['runs'] ?? null];
        $retries = [$a['retries'] ?? null, $b['retries'] ?? null];
        sort($runs);
        sort($retries);
        $victim = ($a['retries'] ?? null) === 1 ? $a : $b;
        $division = $a['division'] ?? null;

        expect($runs)->toBe([1, 2])
            ->and($retries)->toBe([0, 1])
            ->and($victim['retried_sqlstate'] ?? null)->toBe('40P01')
            ->and($victim['entered'] ?? null)->toBe(2)
            ->and($a['entered'] ?? null)->toBe($a['runs'] ?? null)
            ->and($b['entered'] ?? null)->toBe($b['runs'] ?? null)
            ->and($a['locked'] ?? null)->toBe(2)
            ->and($b['locked'] ?? null)->toBe(2);

        if ($locale === null) {
            expect($division)->toBe('not asked');
        } else {
            expect($division)->toBeString()->not->toContain('division by zero');
        }
    }

    expect(DB::table('tenant_acme.staff_days')->count())->toBe(2);
});
