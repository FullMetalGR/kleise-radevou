<?php

use App\Application\Checkpoint;
use App\Application\Clock;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Billing\TrialDeadlines;
use App\Billing\TrialNotice;
use App\Notifications\AccountClosing;
use App\Notifications\TrialEnding;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\FixedClock;
use Tests\Support\RecordingPlatformMessages;

/*
 * Two runs at once of the trial's hourly commands: trial:notify sends a notice once and
 * billing:lapse closes an account once, because each claim is one UPDATE that decides and writes
 * at once. The first worker holds its claim open at a checkpoint, the transaction around it
 * standing in for the instant between the statement's write and its commit; the second runs the
 * whole command, is observed blocked on the claimed row's lock, and once the first commits finds
 * the row moved and claims nothing, so it sends nothing. The first sends after its commit, as
 * the command does. Each worker binds a pinned clock and the recording seam, and answers what it
 * handed the seam.
 */

beforeEach(fn () => app()->instance(Clock::class, FixedClock::at('2026-10-01 09:00')));

afterEach(function () {
    ConcurrentRun::abandonAll();
    DB::statement('drop schema if exists tenant_luna cascade');
});

/**
 * A tenant of the given subscription state and trial end with one owner user, as the claim reads it.
 */
function deadlineTenant(string $state, string $endsAt): void
{
    DB::statement('drop schema if exists tenant_luna cascade');
    $tenant = schemaBehind(billingTenant('luna'));
    billingSet(['subscription_state' => $state, 'entitlements' => '{staff}', 'trial_ends_at' => $endsAt]);
    DB::table('users')->insert(['tenant_id' => $tenant->id, 'name' => 'Ada Person', 'email' => 'ada@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'owner']);
}

/**
 * The first worker: the claim taken inside a transaction held open at the checkpoint "claimed",
 * then committed, then the claimed accounts handed to the seam as the command hands them.
 */
function heldClaim(string $now, string $claim): Closure
{
    return function () use ($now, $claim): array {
        app()->instance(Clock::class, FixedClock::at($now));
        $seam = new RecordingPlatformMessages;
        app()->instance(PlatformMessenger::class, $seam);
        $deadlines = app(TrialDeadlines::class);

        DB::beginTransaction();
        $claimed = $claim === 'close' ? $deadlines->close() : $deadlines->notice(TrialNotice::ThreeDaysLeft);
        Checkpoint::reach('claimed');
        DB::commit();

        foreach ($claimed as $account) {
            $claim === 'close' ? app(AccountClosing::class)->send($account) : app(TrialEnding::class)->send($account, TrialNotice::ThreeDaysLeft);
        }

        return ['claimed' => count($claimed), 'sent' => array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent)];
    };
}

/**
 * The second worker: the whole command, once released from the checkpoint "go".
 */
function wholeCommand(string $now, string $command): Closure
{
    return function () use ($now, $command): array {
        Checkpoint::reach('go');
        app()->instance(Clock::class, FixedClock::at($now));
        $seam = new RecordingPlatformMessages;
        app()->instance(PlatformMessenger::class, $seam);

        return ['exit' => Artisan::call($command), 'output' => trim(Artisan::output()), 'sent' => count($seam->sent)];
    };
}

/**
 * Runs the two workers: the first stopped with its claim open, the second released and observed
 * waiting on the row's lock in the statement starting with the prefix, then the first let go.
 *
 * @return array{wait_event: string, held: array<mixed>, whole: array<mixed>}
 */
function claimedOnce(Closure $held, Closure $whole, string $prefix): array
{
    $run = ConcurrentRun::of(['held' => $held, 'whole' => $whole])->holding('claimed', 'go')->start();
    $run->release();
    $run->awaitCheckpoint('held', 'claimed');
    $run->releaseCheckpoint('go');
    $waiting = lockWaiter($prefix);
    $run->releaseCheckpoint('claimed');
    $outcomes = $run->wait();

    return ['wait_event' => $waiting['wait_event'], 'held' => returnedArray($outcomes['held']), 'whole' => returnedArray($outcomes['whole'])];
}

it('sends the three days notice once when two trial:notify runs claim it at once, the second finding the stamp the first wrote', function () {
    deadlineTenant('trial', '2026-10-08 09:00:00+00');

    $end = claimedOnce(
        heldClaim('2026-10-05 09:00', 'notice'),
        wholeCommand('2026-10-05 09:00', 'trial:notify'),
        'with moved as (update tenants set trial_three_days_sent_at',
    );

    expect($end['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($end['held'])->toBe(['claimed' => 1, 'sent' => ['ada@luna.example trial_notice']])
        ->and($end['whole'])->toBe(['exit' => 0, 'output' => "Sent the three days left notice to 0 tenants.\nSent the ends today notice to 0 tenants.", 'sent' => 0])
        ->and(DB::scalar("select to_char(trial_three_days_sent_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') from tenants where slug = 'luna'"))->toBe('2026-10-05 09:00');
});

it('closes once and sends one closing email when two billing:lapse runs close the same account at once', function () {
    deadlineTenant('canceled', '2026-10-08 09:00:00+00');

    $end = claimedOnce(
        heldClaim('2026-11-07 09:00', 'close'),
        wholeCommand('2026-11-07 09:00', 'billing:lapse'),
        'with moved as (update tenants set closing_at',
    );

    expect($end['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($end['held'])->toBe(['claimed' => 1, 'sent' => ['ada@luna.example closing']])
        ->and($end['whole'])->toBe(['exit' => 0, 'output' => "Lapsed 0 tenants.\nEnded 0 trials.\nClosed 0 accounts.", 'sent' => 0])
        ->and((array) DB::selectOne("select status, to_char(closing_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as closing_at from tenants where slug = 'luna'"))->toBe(['status' => 'closing', 'closing_at' => '2026-11-07 09:00']);
});
