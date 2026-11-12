<?php

use App\Application\Checkpoint;
use App\Http\Controllers\Central\SignupVerifyController;
use App\Tenancy\InvalidSlug;
use App\Tenancy\Signup;
use App\Tenancy\SignupVerification;
use App\Tenancy\SlugProblem;
use App\Tenancy\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concurrency\Harness\ConcurrentRun;

/*
 * The verified signup against another process: two signups racing for one slug end with one row,
 * the unique index refusing the second with the sentence the form shows for a slug a signup in
 * progress holds, the one the same person reads on resubmitting a second later; and two clicks of
 * one link at once build the row once, the second waiting on the row lock the first builds under,
 * and a release, the sweep's give-back, waiting on that same lock and then finding the row active.
 * Worker closures call only autoloaded code.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
});

/**
 * A worker that reserves luna as the signup POST does and answers 'reserved' or the sentence
 * the form would show on the slug.
 */
function reservingWorker(string $email): Closure
{
    return function () use ($email): string {
        Checkpoint::reach('go');

        try {
            app(SignupVerification::class)->reserve(new Signup('luna', 'Luna Studio', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', $email, 'correct horse battery staple', category: 'hair_salon'));
        } catch (InvalidSlug $refused) {
            return $refused->problem->detail();
        }

        return 'reserved';
    };
}

/**
 * A worker that clicks the link through its door and answers where it was sent and how many
 * tenant migrations ran in it.
 */
function clickingWorker(string $token): Closure
{
    return function () use ($token): array {
        Checkpoint::reach('go');
        $migrated = 0;
        Event::listen(MigrationEnded::class, function () use (&$migrated): void {
            $migrated++;
        });

        $answer = app(SignupVerifyController::class)(Request::create('/signup/verify/'.$token), $token);

        return ['location' => $answer->headers->get('Location'), 'migrated' => $migrated];
    };
}

it('ends two signups racing for one slug with one row, the second refused by the unique index as held by a signup in progress', function () {
    $run = ConcurrentRun::of([
        'first' => reservingWorker('first@luna.example'),
        'second' => reservingWorker('second@luna.example'),
    ])->holding(TenantProvisioner::RESERVED, 'go')->start();
    $run->release();
    $run->releaseCheckpoint('go', 'first');
    $run->awaitCheckpoint('first', TenantProvisioner::RESERVED);
    $run->releaseCheckpoint('go', 'second');
    $waiting = lockWaiter('insert into "tenants"');
    $run->releaseCheckpoint(TenantProvisioner::RESERVED, 'first');
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBe('transactionid')
        ->and($outcomes['first']->returned())->toBe('reserved')
        ->and($outcomes['second']->returned())->toBe(SlugProblem::HeldBySignup->detail())
        ->and(DB::table('tenants')->pluck('slug')->all())->toBe(['luna'])
        ->and(DB::table('users')->pluck('email')->all())->toBe(['first@luna.example'])
        ->and(DB::table('users')->whereNotNull('verification_token_hash')->count())->toBe(1)
        ->and(DB::table('subscriptions')->count())->toBe(0);
});

it('builds the row once when two clicks of one link come at once, the second waiting on the row lock the first builds under and landing on sign-in', function () {
    ['tenant' => $tenant, 'token' => $token] = app(SignupVerification::class)->reserve(new Signup('luna', 'Luna Studio', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', 'owner@luna.example', 'correct horse battery staple', category: 'hair_salon'));

    $run = ConcurrentRun::of([
        'first' => clickingWorker($token),
        'second' => clickingWorker($token),
    ])->holding(TenantProvisioner::BUILD_LOCKED, 'go')->start();
    $run->release();
    $run->releaseCheckpoint('go', 'first');
    $run->awaitCheckpoint('first', TenantProvisioner::BUILD_LOCKED);
    $run->releaseCheckpoint(TenantProvisioner::BUILD_LOCKED, 'second');
    $run->releaseCheckpoint('go', 'second');
    $waiting = lockWaiter('select * from "tenants"');
    $secondFinishedWhileFirstBuilt = $run->hasOutcome('second');
    $run->releaseCheckpoint(TenantProvisioner::BUILD_LOCKED, 'first');
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($secondFinishedWhileFirstBuilt)->toBeFalse()
        ->and(returnedArray($outcomes['first']))->toBe(['location' => centralUrl('/signup/luna/pending'), 'migrated' => 38])
        ->and(returnedArray($outcomes['second']))->toBe(['location' => tenantUrl('luna', '/admin/login'), 'migrated' => 0])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('active')
        ->and(DB::table('tenants')->where('slug', 'luna')->value('subscription_state'))->toBe('trial')
        ->and(DB::table('tenant_luna.migrations')->count())->toBe(38)
        ->and(DB::table('tenant_luna.staff')->where('is_implicit', true)->count())->toBe(1)
        ->and(DB::table('users')->where('tenant_id', $tenant->id)->whereNotNull('email_verified_at')->count())->toBe(1)
        ->and(DB::table('users')->where('tenant_id', $tenant->id)->value('verification_token_hash'))->toBe(hash('sha256', $token));
});

it('makes a release wait behind a click building the row, then answer false with the tenant active', function () {
    ['tenant' => $tenant, 'token' => $token] = app(SignupVerification::class)->reserve(new Signup('luna', 'Luna Studio', new DateTimeZone('Europe/Athens'), 'EUR', 'Alex Owner', 'owner@luna.example', 'correct horse battery staple', category: 'hair_salon'));

    $run = ConcurrentRun::of([
        'click' => clickingWorker($token),
        'release' => function (): bool {
            Checkpoint::reach('go');

            return app(TenantProvisioner::class)->release(Tenant::query()->where('slug', 'luna')->sole());
        },
    ])->holding(TenantProvisioner::BUILD_LOCKED, 'go')->start();
    $run->release();
    $run->releaseCheckpoint('go', 'click');
    $run->awaitCheckpoint('click', TenantProvisioner::BUILD_LOCKED);
    $run->releaseCheckpoint('go', 'release');
    $waiting = lockWaiter('select t.id from tenants');
    $releasedWhileBuilt = $run->hasOutcome('release');
    $run->releaseCheckpoint(TenantProvisioner::BUILD_LOCKED, 'click');
    $outcomes = $run->wait();

    expect($waiting['wait_event'])->toBeIn(['transactionid', 'tuple'])
        ->and($releasedWhileBuilt)->toBeFalse()
        ->and($outcomes['release']->returned())->toBeFalse()
        ->and(returnedArray($outcomes['click']))->toBe(['location' => centralUrl('/signup/luna/pending'), 'migrated' => 38])
        ->and(DB::table('tenants')->where('slug', 'luna')->value('status'))->toBe('active')
        ->and(DB::table('users')->where('tenant_id', $tenant->id)->count())->toBe(1);
});
