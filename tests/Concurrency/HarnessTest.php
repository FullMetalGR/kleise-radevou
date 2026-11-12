<?php

use App\Application\Checkpoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\ConcurrencyTestCase;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Concurrency\Harness\HarnessFailure;
use Tests\Concurrency\Harness\Outcome;

/*
 * The harness itself, one behaviour per test. Worker closures run in another process, so they
 * call only autoloaded code and the framework: no helper declared in a test file or in Pest.php
 * exists over there.
 */

/**
 * One fact from every worker, in outcome order.
 *
 * @param  array<string, Outcome>  $outcomes
 * @return list<int|string>
 */
function fact(array $outcomes, string $key): array
{
    return array_map(function (Outcome $outcome) use ($key): int|string {
        $value = returnedArray($outcome)[$key] ?? null;

        assert(is_int($value) || is_string($value));

        return $value;
    }, array_values($outcomes));
}

/**
 * The two database-clock readings a worker returned.
 *
 * @return array{float, float}
 */
function interval(Outcome $outcome): array
{
    [$start, $end] = returnedArray($outcome);

    assert(is_float($start) && is_float($end));

    return [$start, $end];
}

it('runs every worker in its own process on its own connection and reports every outcome', function () {
    $identity = fn (): array => [
        'process' => getmypid(),
        'backend' => DB::scalar('select pg_backend_pid()'),
        'database' => DB::scalar('select current_database()'),
    ];

    $outcomes = ConcurrentRun::of(['a' => $identity, 'b' => $identity, 'c' => $identity])->run();

    expect(array_keys($outcomes))->toBe(['a', 'b', 'c']);

    $processes = fact($outcomes, 'process');
    $backends = fact($outcomes, 'backend');

    expect(array_unique($processes))->toHaveCount(3)
        ->and($processes)->not->toContain(getmypid())
        ->and(array_unique($backends))->toHaveCount(3)
        ->and($backends)->not->toContain(DB::scalar('select pg_backend_pid()'))
        ->and(array_unique(fact($outcomes, 'database')))->toBe([DB::connection()->getDatabaseName()]);
});

/**
 * The database's clock, so parent and workers are timed by one source.
 */
function databaseClock(): float
{
    $epoch = DB::scalar('select extract(epoch from clock_timestamp())::float8');

    assert(is_float($epoch));

    return $epoch;
}

it('holds every worker at the barrier until the parent releases it', function () {
    $stamp = fn (): mixed => DB::scalar('select extract(epoch from clock_timestamp())::float8');

    $run = ConcurrentRun::of(['a' => $stamp, 'b' => $stamp, 'c' => $stamp])->start();
    $released = databaseClock();
    $run->release();

    foreach ($run->wait() as $outcome) {
        expect($outcome->returned())->toBeFloat()->toBeGreaterThan($released);
    }
});

it('runs two workers that each hold a row lock for one second in well under two seconds', function () {
    DB::table('cache')->insert([
        ['key' => 'a', 'value' => 'x', 'expiration' => 0],
        ['key' => 'b', 'value' => 'x', 'expiration' => 0],
    ]);

    $holdRow = function (string $key): Closure {
        return function () use ($key): mixed {
            return DB::transaction(function () use ($key): array {
                DB::table('cache')->where('key', $key)->lockForUpdate()->first();
                $start = DB::scalar('select extract(epoch from clock_timestamp())::float8');
                DB::statement('select pg_sleep(1)');
                $end = DB::scalar('select extract(epoch from clock_timestamp())::float8');

                return [$start, $end];
            });
        };
    };

    $run = ConcurrentRun::of(['a' => $holdRow('a'), 'b' => $holdRow('b')])->start();
    $startedAt = microtime(true);
    $run->release();
    $outcomes = $run->wait();
    $elapsed = microtime(true) - $startedAt;

    [$aStart, $aEnd] = interval($outcomes['a']);
    [$bStart, $bEnd] = interval($outcomes['b']);

    expect($elapsed)->toBeLessThan(2.0)
        ->and($aStart)->toBeLessThan($bEnd)
        ->and($bStart)->toBeLessThan($aEnd);
});

it('reports a thrown exception by class and SQLSTATE and still finishes the run', function () {
    $outcomes = ConcurrentRun::of([
        'domain' => fn () => throw new RuntimeException('boom'),
        'database' => fn (): bool => DB::statement('select 1/0'),
        'fine' => fn (): string => 'fine',
    ])->run();

    expect($outcomes['domain']->threw())->toBeTrue()
        ->and($outcomes['domain']->exceptionClass)->toBe(RuntimeException::class)
        ->and($outcomes['domain']->message)->toBe('boom')
        ->and($outcomes['domain']->sqlstate)->toBeNull()
        ->and($outcomes['database']->exceptionClass)->toBe(QueryException::class)
        ->and($outcomes['database']->sqlstate)->toBe('22012')
        ->and($outcomes['fine']->threw())->toBeFalse()
        ->and($outcomes['fine']->returned())->toBe('fine');
});

it('returns at once from a checkpoint nobody holds and blocks at a held one until the test releases it', function () {
    Checkpoint::reach('middle');

    $worker = function (): mixed {
        Checkpoint::reach('unheld');
        Checkpoint::reach('middle');

        return DB::table('cache')->count();
    };

    $run = ConcurrentRun::of(['w' => $worker])->holding('middle')->start();
    $run->release();
    $run->awaitCheckpoint('w', 'middle');

    expect($run->hasOutcome('w'))->toBeFalse();

    DB::table('cache')->insert(['key' => 'while-blocked', 'value' => 'x', 'expiration' => 0]);
    $run->releaseCheckpoint('middle');

    expect($run->wait()['w']->returned())->toBe(1);
});

it('lets one process wait on a row lock another process holds inside its own transaction', function () {
    DB::table('cache')->insert(['key' => 'shared', 'value' => 'x', 'expiration' => 0]);

    $holder = function (): mixed {
        return DB::transaction(function (): string {
            DB::statement("set local lock_timeout = '200ms'");
            DB::table('cache')->where('key', 'shared')->lockForUpdate()->first();
            Checkpoint::reach('holding');

            return 'held';
        });
    };
    $waiter = function (): mixed {
        Checkpoint::reach('go');

        return DB::transaction(function (): string {
            DB::statement("set local lock_timeout = '200ms'");
            DB::table('cache')->where('key', 'shared')->lockForUpdate()->first();

            return 'took it';
        });
    };

    $run = ConcurrentRun::of(['holder' => $holder, 'waiter' => $waiter])->holding('holding', 'go')->start();
    $run->release();
    $run->awaitCheckpoint('holder', 'holding');
    $run->releaseCheckpoint('go');
    $waited = $run->outcomeOf('waiter');
    $run->releaseCheckpoint('holding');
    $outcomes = $run->wait();

    expect($waited->exceptionClass)->toBe(QueryException::class)
        ->and($waited->sqlstate)->toBe('55P03')
        ->and($outcomes['holder']->returned())->toBe('held');
});

it('kills a worker past its budget, ends its database session, and fails naming it', function () {
    $run = ConcurrentRun::of([
        'quick' => fn (): int => 1,
        'slow' => fn (): bool => DB::statement('select pg_sleep(30)'),
    ])->budget(1.5);
    $startedAt = microtime(true);

    expect(fn () => $run->run())->toThrow(HarnessFailure::class, '"slow"');

    expect(microtime(true) - $startedAt)->toBeLessThan(6.0)
        ->and(
            DB::table('pg_stat_activity')
                ->where('query', 'like', '%pg_sleep(30)%')
                ->where('pid', '<>', DB::raw('pg_backend_pid()'))
                ->count()
        )->toBe(0);
});

it('fails naming a worker that dies without reporting', function () {
    $run = ConcurrentRun::of(['dies' => function (): void {
        exit(3);
    }]);

    expect(fn () => $run->run())->toThrow(HarnessFailure::class, '"dies" ended with exit code 3');
});

it('refuses a worker closure that uses $this, since the test case does not exist in a worker', function () {
    expect(fn () => ConcurrentRun::of(['w' => fn () => $this]))->toThrow(LogicException::class, '$this');
});

it('refuses a transaction-wrapping refresh anywhere in this suite', function () {
    $wrapped = new class('wrapped') extends ConcurrencyTestCase
    {
        use RefreshDatabase;

        public function boot(): void
        {
            $this->setUp();
        }
    };

    expect(fn () => $wrapped->boot())->toThrow(LogicException::class, 'RefreshDatabase');
});
