<?php

use App\Http\Controllers\MyBookingsController;
use App\Http\MyBookingsCookie;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Concurrency\Harness\ConcurrentRun;

/*
 * The My bookings link's button against itself: two presses of one link's button at once open one
 * session. The opening is one statement, CustomerAccess::openSession, which stamps the link used
 * and inserts the session from the row it stamped; the second press's update waits on the first's
 * row lock and, at READ COMMITTED, re-reads the row, finds it used and inserts nothing, so it
 * answers as a used link does.
 *
 * Each worker is a POST through the application's HTTP kernel, as the browser's press is: tenant
 * resolution, the session, the cross-site guard (which stands down under the test runner, as it
 * does in every feature test) and the controller. To make the two meet on the row rather than
 * hope they do, this process holds the link's row lock before the barrier opens, waits until both
 * presses are blocked on it inside the opening statement, and lets go.
 *
 * The workers stand on the system clock, so the link is written relative to the database's now
 * and spells no date. Worker closures call only autoloaded code and the framework.
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
 * A worker that presses the link page's button: one POST to the link's address through the
 * kernel, answering where it was sent, whether it set the session's cookie, and the sentence it
 * flashed.
 */
function pressingWorker(string $url): Closure
{
    return function () use ($url): array {
        $response = app(HttpKernel::class)->handle(Request::create($url, 'POST'));
        $cookies = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());

        return [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'cookie' => in_array(MyBookingsCookie::NAME, $cookies, true),
            'flash' => session(MyBookingsController::FLASH),
        ];
    };
}

/**
 * Waits, bounded, until a number of backends are blocked on a lock inside the opening statement,
 * as the activity view reports them. The statement runs outside the transaction runner, so no
 * lock timeout aborts a waiter; the bound covers each press's first request through the kernel,
 * which loads the routes and the middleware, under the run's budget. It is asked inside the
 * transaction holding the row, which keeps the view's first answer for the transaction's life,
 * so each look discards that snapshot first.
 */
function pressingWaiters(int $count, float $seconds = 10.0): void
{
    $deadline = microtime(true) + $seconds;

    do {
        DB::select('select pg_stat_clear_snapshot()');
        $waiting = DB::scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and query like 'with opened as%'");

        if ($waiting === $count) {
            return;
        }

        usleep(10_000);
    } while (microtime(true) < $deadline);

    Assert::fail(sprintf('%d backends were not blocked in the opening statement within %.1f seconds.', $count, $seconds));
}

it('opens one session when two presses of one link\'s button race, the second waiting on the first\'s row lock and finding the link used', function () {
    $token = str_repeat('c', 64);
    DB::insert("insert into tenant_acme.customer_links (email, token_hash, created_at, expires_at) values ('casey@example.test', ?, now(), now() + interval '15 minutes')", [hash('sha256', $token)]);
    $url = tenantUrl('acme', '/my-bookings/'.$token);

    // The link's row, locked by this process, so both presses block on it inside the statement.
    DB::beginTransaction();
    DB::select('select id from tenant_acme.customer_links where token_hash = ? for update', [hash('sha256', $token)]);

    $run = ConcurrentRun::of([
        'first' => pressingWorker($url),
        'second' => pressingWorker($url),
    ])->budget(20.0)->start();
    $run->release();
    pressingWaiters(2);
    $bothWaited = ! $run->hasOutcome('first') && ! $run->hasOutcome('second');
    DB::rollBack();
    $outcomes = $run->wait();

    $answers = [returnedArray($outcomes['first']), returnedArray($outcomes['second'])];
    $opened = array_values(array_filter($answers, fn (array $answer): bool => $answer['cookie'] === true));
    $refused = array_values(array_filter($answers, fn (array $answer): bool => $answer['cookie'] === false));

    expect($bothWaited)->toBeTrue()
        ->and($opened)->toBe([['status' => 302, 'location' => tenantUrl('acme', '/my-bookings'), 'cookie' => true, 'flash' => null]])
        ->and($refused)->toBe([['status' => 302, 'location' => tenantUrl('acme', '/my-bookings'), 'cookie' => false, 'flash' => ['kind' => 'warning', 'message' => MyBookingsController::INVALID]]])
        ->and(DB::table('tenant_acme.customer_sessions')->count())->toBe(1)
        ->and(DB::table('tenant_acme.customer_sessions')->selectRaw('email::text as email')->value('email'))->toBe('casey@example.test')
        ->and(DB::table('tenant_acme.customer_links')->whereNotNull('used_at')->count())->toBe(1);
});
