<?php

use App\Http\AdminSession;
use App\Http\Controllers\Admin\SupportController;
use App\Platform\SupportSessions;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Concurrency\Harness\ConcurrentRun;

/*
 * The support session against itself. Two GETs of one support link inside its five minutes open
 * one session: the claim is one statement, SupportSessions::claim, and the second's update waits
 * on the first's row lock and, at READ COMMITTED, re-reads the row, finds its hash cleared and
 * moves nothing, so it answers the sign-in page as a used link does. Two ends of one session at
 * once, the console's End against the admin's Sign out, record one support_ended:
 * SupportSessions::end writes ended_at in one statement, and the loser's update re-reads the
 * winner's ended_at and moves nothing, recording nothing.
 *
 * Each claim is a GET through the application's HTTP kernel, as the browser's is. The two ends
 * are each one's own code below its door, since a door reads its session from the browser's
 * cookie, which a worker holds none of: the console's End is SupportSessions::end in the name of
 * the operator who pressed it, as SupportEndController calls it, and the Sign out is
 * AdminSession::endSupport on a request whose session names the row, as LogoutController calls
 * it, the row's own operator acting. To make the two meet on the row rather than hope they do,
 * this process holds the row's lock before the barrier opens, waits until both are blocked on it
 * inside their update, and lets go.
 *
 * The workers stand on the system clock, so the row is written relative to the database's now and
 * spells no date. Worker closures call only autoloaded code and the framework.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
});

afterEach(function () {
    // A worker still blocked on the row lock would make the drop wait; abandon the runs first.
    ConcurrentRun::abandonAll();

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    DB::statement('drop schema if exists tenant_acme cascade');
});

/**
 * A support_sessions row of acme for the operator given, pressed now and ending sixty minutes
 * on: unopened under the token's hash, or opened now with the hash cleared. Answers its id.
 */
function supportRaceRow(int $operatorId, ?string $token): int
{
    $tenant = Tenant::query()->where('slug', 'acme')->sole()->id;
    $id = $token === null
        ? DB::scalar("insert into support_sessions (operator_id, tenant_id, opened_at, expires_at, created_at) values (?, ?, now(), now() + interval '60 minutes', now()) returning id", [$operatorId, $tenant])
        : DB::scalar("insert into support_sessions (operator_id, tenant_id, token_hash, expires_at, created_at) values (?, ?, ?, now() + interval '60 minutes', now()) returning id", [$operatorId, $tenant, hash('sha256', $token)]);
    assert(is_int($id));

    return $id;
}

/**
 * A worker that opens the link: one GET to its address through the kernel, answering where it
 * was sent, the support key its session holds and the sentence it flashed.
 */
function supportRaceClaimer(string $url): Closure
{
    return function () use ($url): array {
        $response = app(HttpKernel::class)->handle(Request::create($url));

        return [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'support' => session(AdminSession::SUPPORT_KEY),
            'flash' => session('flash'),
        ];
    };
}

/**
 * A worker that signs out of acme's admin in a browser whose session names the row: the admin's
 * end, AdminSession::endSupport, answering whether the session still holds the key afterwards.
 */
function supportRaceSignOut(string $url, int $id): Closure
{
    return function () use ($url, $id): array {
        app(CurrentTenant::class)->bind(Tenant::query()->where('slug', 'acme')->sole());
        $session = new Store('admin', new ArraySessionHandler(60));
        $session->put(AdminSession::SUPPORT_KEY, $id);
        $request = Request::create($url, 'POST');
        $request->setLaravelSession($session);

        app(AdminSession::class)->endSupport($request);

        return ['kept' => $session->has(AdminSession::SUPPORT_KEY)];
    };
}

/**
 * A worker that presses the console's End on the row as the operator given, answering whether
 * the row moved.
 */
function supportRaceConsoleEnd(int $id, int $operatorId): Closure
{
    return fn (): bool => app(SupportSessions::class)->end($id, $operatorId);
}

/**
 * Waits, bounded, until a number of backends are blocked on a lock inside an update of
 * support_sessions starting with the prefix, as the activity view reports them. Neither update
 * runs under the transaction runner, so no lock timeout aborts a waiter; the bound covers each
 * worker's boot and a claim's first request through the kernel, under the run's budget. It is
 * asked inside the transaction holding the row, which keeps the view's first answer for the
 * transaction's life, so each look discards that snapshot first.
 */
function supportRaceWaiters(string $prefix, int $count, float $seconds = 10.0): void
{
    $deadline = microtime(true) + $seconds;

    do {
        DB::select('select pg_stat_clear_snapshot()');
        $waiting = DB::scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and query like ?", [$prefix.'%']);

        if ($waiting === $count) {
            return;
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    Assert::fail(sprintf('%d backends were not blocked in a statement starting "%s" within %.1f seconds.', $count, $prefix, $seconds));
}

it('opens one session when two GETs of one link race inside its five minutes, the second waiting on the first\'s row lock and finding the link used', function () {
    $token = str_repeat('d', 64);
    $id = supportRaceRow(addOperator()->id, $token);
    $url = tenantUrl('acme', '/admin/support/'.$token);

    // The row, locked by this process, so both claims block on it inside the statement.
    DB::beginTransaction();
    DB::select('select id from support_sessions where id = ? for update', [$id]);

    $run = ConcurrentRun::of([
        'first' => supportRaceClaimer($url),
        'second' => supportRaceClaimer($url),
    ])->budget(20.0)->start();
    $run->release();
    supportRaceWaiters('update support_sessions set opened_at', 2);
    $bothWaited = ! $run->hasOutcome('first') && ! $run->hasOutcome('second');
    DB::rollBack();
    $outcomes = $run->wait();

    $answers = [returnedArray($outcomes['first']), returnedArray($outcomes['second'])];
    $opened = array_values(array_filter($answers, fn (array $answer): bool => $answer['support'] !== null));
    $refused = array_values(array_filter($answers, fn (array $answer): bool => $answer['support'] === null));
    $row = DB::selectOne('select token_hash, opened_at is not null as opened, ended_at from support_sessions where id = ?', [$id]);

    expect($bothWaited)->toBeTrue()
        ->and($opened)->toBe([['status' => 302, 'location' => tenantUrl('acme', '/admin'), 'support' => $id, 'flash' => null]])
        ->and($refused)->toBe([['status' => 302, 'location' => tenantUrl('acme', '/admin/login'), 'support' => null, 'flash' => ['kind' => 'error', 'message' => adminLine(SupportController::INVALID)]]])
        ->and((array) $row)->toBe(['token_hash' => null, 'opened' => true, 'ended_at' => null])
        ->and(DB::table('support_sessions')->count())->toBe(1)
        ->and(DB::table('platform_audit_log')->count())->toBe(0);
});

it('records one end when the console\'s End and the admin\'s Sign out race on one open session, the second waiting on the first\'s row lock and finding the session ended', function () {
    $pat = addOperator();
    $sam = addOperator('sam@inbox.example', name: 'Sam Operator');
    $id = supportRaceRow($pat->id, null);

    // The row, locked by this process, so both ends block on it inside the statement.
    DB::beginTransaction();
    DB::select('select id from support_sessions where id = ? for update', [$id]);

    $run = ConcurrentRun::of([
        'console' => supportRaceConsoleEnd($id, $sam->id),
        'signout' => supportRaceSignOut(tenantUrl('acme', '/admin/logout'), $id),
    ])->budget(20.0)->start();
    $run->release();
    supportRaceWaiters('update support_sessions set ended_at', 2);
    $bothWaited = ! $run->hasOutcome('console') && ! $run->hasOutcome('signout');
    DB::rollBack();
    $outcomes = $run->wait();

    $consoleEnded = $outcomes['console']->returned();
    $acme = Tenant::query()->where('slug', 'acme')->sole()->id;

    expect($bothWaited)->toBeTrue()
        ->and($consoleEnded)->toBeBool()
        ->and(returnedArray($outcomes['signout']))->toBe(['kept' => false])
        ->and(DB::table('support_sessions')->where('id', $id)->whereNotNull('ended_at')->count())->toBe(1)
        ->and(DB::table('platform_audit_log')->orderBy('id')->get(['operator_id', 'action', 'tenant_id'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['operator_id' => $consoleEnded === true ? $sam->id : $pat->id, 'action' => 'support_ended', 'tenant_id' => $acme],
        ]);
});
