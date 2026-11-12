<?php

use App\Application\Checkpoint;
use App\Tenancy\Role;
use App\Tenancy\Team;
use App\Tenancy\TeamRefused;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;

/*
 * Two owners demoting each other at once, the Team screen's role change: the change takes the
 * tenant's owner rows for update before it counts them, so the second demotion waits for the
 * first and then counts one owner left, refused as the last owner with its role kept. Without
 * the lock both would count two owners and the tenant would be left with none. The first
 * worker's change runs inside a transaction held open at a checkpoint, standing in for the
 * instant between the change's write and its commit; the second is released and observed blocked
 * on the owner rows' lock in the change's own statement, then the first commits.
 */

beforeEach(function () {
    $tenant = billingTenant('luna');
    DB::table('users')->insert([
        ['tenant_id' => $tenant->id, 'name' => 'Ada Person', 'email' => 'ada@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'owner'],
        ['tenant_id' => $tenant->id, 'name' => 'Bea Person', 'email' => 'bea@luna.example', 'password' => bcrypt('correct horse battery staple'), 'role' => 'owner'],
    ]);
});

/**
 * One owner's demotion of another to manager: held open at "demoted" before its commit where
 * $hold, else released by "go" first; answering the role written or the refusal.
 */
function demotingPress(int $userId, bool $hold): Closure
{
    return function () use ($userId, $hold): array {
        if (! $hold) {
            Checkpoint::reach('go');
        }

        $tenant = Tenant::query()->where('slug', 'luna')->sole();

        try {
            if ($hold) {
                DB::beginTransaction();
            }

            $user = app(Team::class)->changeRole($tenant, $userId, Role::Manager);

            if ($hold) {
                Checkpoint::reach('demoted');
                DB::commit();
            }

            return ['role' => $user?->role->value];
        } catch (TeamRefused $refused) {
            return ['refused' => $refused->refusal];
        }
    };
}

it('leaves one owner when two owners demote each other at once, the second waiting on the owner rows\' lock and refused as the last owner', function () {
    $ada = DB::table('users')->where('email', 'ada@luna.example')->value('id');
    $bea = DB::table('users')->where('email', 'bea@luna.example')->value('id');
    assert(is_int($ada) && is_int($bea));

    $run = ConcurrentRun::of(['held' => demotingPress($bea, true), 'second' => demotingPress($ada, false)])->holding('demoted', 'go')->start();
    $run->release();
    $run->awaitCheckpoint('held', 'demoted');
    $run->releaseCheckpoint('go');
    $waiting = lockWaiter('select "id" from "users"');
    $run->releaseCheckpoint('demoted');
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and(returnedArray($outcomes['held']))->toBe(['role' => 'manager'])
        ->and(returnedArray($outcomes['second']))->toBe(['refused' => TeamRefused::lastOwner()->refusal])
        ->and(DB::table('users')->whereIn('email', ['ada@luna.example', 'bea@luna.example'])->orderBy('email')->pluck('role', 'email')->all())->toBe(['ada@luna.example' => 'owner', 'bea@luna.example' => 'manager']);
});
