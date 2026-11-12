<?php

use App\Application\Checkpoint;
use App\Application\Notification\PlatformMessage;
use App\Application\Notification\PlatformMessenger;
use App\Billing\Entitlements;
use App\Platform\FreeTenantRefused;
use App\Platform\FreeTenants;
use App\Platform\NewFreeTenant;
use App\Platform\Operator;
use App\Site\SiteLanguage;
use App\Tenancy\SlugProblem;
use App\Tenancy\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Harness\ConcurrentRun;
use Tests\Support\RecordingPlatformMessages;

/*
 * Two of the console's free tenant creates of one slug at once: one tenant and one refusal on
 * the slug's field, whichever way the two meet. Where both claims judged the slug free before
 * either wrote its row, the second insert meets the unique index and is answered as the slug
 * taken, rather than escaping as a 500. Where the second create finds the first's row
 * provisioning with no owner yet, the first's build in flight, it leaves that row unbuilt and
 * refuses it as taken, rather than resuming it into a second owner, a second tenant_created, or
 * a wait on the schema's name ending in a 500. The first builds, records its creation and mails
 * its owner once. Worker closures call only autoloaded code.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
});

/**
 * A worker that creates acme as the console's POST does, its owner the address given, and
 * answers 'created' or the sentence the form shows on the slug, with what it handed the
 * platform's seam.
 */
function freeCreatingWorker(string $email, int $operatorId): Closure
{
    return function () use ($email, $operatorId): array {
        Checkpoint::reach('go');
        $seam = new RecordingPlatformMessages;
        app()->instance(PlatformMessenger::class, $seam);
        $details = new NewFreeTenant('acme', 'Acme Barbers', new DateTimeZone('Europe/Lisbon'), 'GBP', null, 'Alex Owner', $email, Entitlements::none(), SiteLanguage::English);

        try {
            app(FreeTenants::class)->create($details, Operator::query()->findOrFail($operatorId));
            $answer = 'created';
        } catch (FreeTenantRefused $refused) {
            $answer = $refused->getMessage();
        }

        return ['answer' => $answer, 'sent' => array_map(fn (PlatformMessage $message): string => $message->toAddress.' '.$message->kind->value, $seam->sent)];
    };
}

/**
 * What the two creates left: the tenant rows, the users, the audit's actions and the tenant
 * migrations the one schema holds.
 *
 * @return array<string, mixed>
 */
function freeRaceRows(): array
{
    return [
        'tenants' => DB::table('tenants')->get(['slug', 'status'])->map(fn (object $row): array => (array) $row)->all(),
        'users' => DB::table('users')->orderBy('id')->pluck('email')->all(),
        'audit' => DB::table('platform_audit_log')->orderBy('id')->pluck('action')->all(),
        'migrations' => DB::table('tenant_acme.migrations')->count(),
    ];
}

it('ends two creates whose claims both judged the slug free with one tenant, the second insert meeting the unique index and refused as taken on the slug', function () {
    $operator = addOperator();

    $run = ConcurrentRun::of([
        'first' => freeCreatingWorker('first@acme.example', $operator->id),
        'second' => freeCreatingWorker('second@acme.example', $operator->id),
    ])->holding(TenantProvisioner::CLAIMING)->start();
    $run->release();
    $run->awaitCheckpoint('first', TenantProvisioner::CLAIMING);
    $run->awaitCheckpoint('second', TenantProvisioner::CLAIMING);
    $run->releaseCheckpoint(TenantProvisioner::CLAIMING, 'first');
    $first = returnedArray($run->outcomeOf('first'));
    $run->releaseCheckpoint(TenantProvisioner::CLAIMING, 'second');
    $outcomes = $run->wait();

    expect($first)->toBe(['answer' => 'created', 'sent' => ['first@acme.example set_password']])
        ->and(returnedArray($outcomes['second']))->toBe(['answer' => SlugProblem::Taken->detail(), 'sent' => []])
        ->and(freeRaceRows())->toBe([
            'tenants' => [['slug' => 'acme', 'status' => 'active']],
            'users' => ['first@acme.example'],
            'audit' => ['tenant_created'],
            'migrations' => 38,
        ]);
});

it('leaves the first create\'s row unbuilt and refuses it as taken when the second meets it provisioning with no owner yet, the first\'s build in flight, which then commits once', function () {
    $operator = addOperator();

    $run = ConcurrentRun::of([
        'first' => freeCreatingWorker('first@acme.example', $operator->id),
        'second' => freeCreatingWorker('second@acme.example', $operator->id),
    ])->holding(TenantProvisioner::BUILDING, 'go')->start();
    $run->release();
    $run->releaseCheckpoint('go', 'first');
    $run->awaitCheckpoint('first', TenantProvisioner::BUILDING);
    $run->releaseCheckpoint(TenantProvisioner::BUILDING, 'second');
    $run->releaseCheckpoint('go', 'second');
    $second = returnedArray($run->outcomeOf('second'));
    $whileBuilding = DB::table('tenants')->where('slug', 'acme')->value('status');
    $run->releaseCheckpoint(TenantProvisioner::BUILDING, 'first');
    $outcomes = $run->wait();

    expect($second)->toBe(['answer' => SlugProblem::Taken->detail(), 'sent' => []])
        ->and($whileBuilding)->toBe('provisioning')
        ->and(returnedArray($outcomes['first']))->toBe(['answer' => 'created', 'sent' => ['first@acme.example set_password']])
        ->and(freeRaceRows())->toBe([
            'tenants' => [['slug' => 'acme', 'status' => 'active']],
            'users' => ['first@acme.example'],
            'audit' => ['tenant_created'],
            'migrations' => 38,
        ]);
});
