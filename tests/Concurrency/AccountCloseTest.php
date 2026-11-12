<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Platform\AccountClose;
use App\Platform\CloseRefused;
use App\Platform\OwnerClose;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

/*
 * Two closes of one account at once: the owner's close takes the tenant row for update before it
 * reads the status, so a second press, in another tab or by another owner, waits for the first
 * and then finds the row closing, refused with nothing written and nothing sent. The first
 * worker's close runs inside a transaction held open at a checkpoint, standing in for the
 * instant between the close's write and its commit; the second is released and observed blocked
 * on the row's lock in the close's own statement, then the first commits. Each worker binds the
 * pinned clock and the recording seam, and answers what it handed the seam.
 */

beforeEach(function () {
    app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00'));
    DB::statement('drop schema if exists tenant_luna cascade');
    $tenant = schemaBehind(billingTenant('luna'));
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Ada Person', 'email' => 'ada@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'owner']);
});

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_luna cascade');
});

/**
 * One owner's close of luna on the pinned clock: held open at "closed" before its commit where
 * $hold, else released by "go" first; answering the instant it wrote or the refusal's reason,
 * and what it handed the seam.
 */
function closingPress(bool $hold): Closure
{
    return function () use ($hold): array {
        if (! $hold) {
            Checkpoint::reach('go');
        }

        app()->instance(Clock::class, FixedClock::at('2026-11-02 06:00'));
        $seam = new RecordingPlatformMessages;
        app()->instance(PlatformMessenger::class, $seam);
        $tenant = Tenant::query()->where('slug', 'luna')->sole();

        try {
            if ($hold) {
                DB::beginTransaction();
            }

            $answer = ['closed' => app(AccountClose::class)->close($tenant, app(OwnerClose::class))->format(DATE_ATOM)];

            if ($hold) {
                Checkpoint::reach('closed');
                DB::commit();
            }
        } catch (CloseRefused $refused) {
            $answer = ['refused' => $refused->reason];
        }

        return [...$answer, 'sent' => array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent)];
    };
}

it('closes once and mails once when two presses close the same account at once, the second waiting on the row\'s lock and refused as already closing', function () {
    $run = ConcurrentRun::of(['held' => closingPress(true), 'second' => closingPress(false)])->holding('closed', 'go')->start();
    $run->release();
    $run->awaitCheckpoint('held', 'closed');
    $run->releaseCheckpoint('go');
    $waiting = lockWaiter('select t.slug, t.name');
    $run->releaseCheckpoint('closed');
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['held']))->toBe(['closed' => '2026-11-02T06:00:00+00:00', 'sent' => ['ada@luna.example closing']])
        ->and(returnedArray($outcomes['second']))->toBe(['refused' => 'already_closing', 'sent' => []])
        ->and((array) DB::selectOne("select status, to_char(closing_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as closing_at from tenants where slug = 'luna'"))->toBe(['status' => 'closing', 'closing_at' => '2026-11-02 06:00']);
});
