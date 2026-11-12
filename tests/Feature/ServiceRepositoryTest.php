<?php

use App\Domain\Enum\ApprovalTimeout;
use App\Domain\Enum\PaymentMode;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\PostgresServiceRepository;
use App\Tenancy\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/*
 * The engine's view of a tenant's services over real rows: which services come back, which
 * staff members each names, the columns the catalog withholds, and that the read stays inside
 * the tenant asked for. The read opens no transaction, so the one this suite wraps each test
 * in is harmless.
 */

function serviceRepository(string $slug = 'acme', Pool $pool = Pool::Whole): PostgresServiceRepository
{
    return new PostgresServiceRepository(app(DatabaseManager::class), Tenant::query()->where('slug', $slug)->sole(), $pool);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function repositoryStaff(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.staff')->insertGetId(['name' => $name, 'status' => 'active', ...$overrides]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function repositoryService(string $schema, string $name, array $overrides = []): int
{
    return DB::table($schema.'.services')->insertGetId(serviceRow($name, $overrides));
}

function repositoryAssign(string $schema, int $serviceId, int $staffId): void
{
    DB::table($schema.'.service_staff')->insert(['service_id' => $serviceId, 'staff_id' => $staffId]);
}

function repositoryImplicitId(string $schema): int
{
    $id = DB::table($schema.'.staff')->where('is_implicit', true)->value('id');

    assert(is_int($id));

    return $id;
}

it('answers the active services among the ids asked for, keyed by id, each as the engine sees it', function () {
    provisionTenant('acme');
    $sam = repositoryStaff('tenant_acme', 'Sam');
    $colour = repositoryService('tenant_acme', 'Colour', [
        'duration_min' => 90, 'buffer_before_min' => 5, 'buffer_after_min' => 10, 'processing_gap_min' => 30, 'lead_time_min' => 120, 'horizon_days' => 14,
        'price_minor' => 8000, 'payment_mode' => 'online', 'requires_approval' => true, 'approval_hold_hours' => 6, 'on_approval_timeout' => 'auto_approve',
    ]);
    $cut = repositoryService('tenant_acme', 'Cut', ['duration_min' => 45]);
    $perm = repositoryService('tenant_acme', 'Perm', ['status' => 'inactive']);
    repositoryAssign('tenant_acme', $colour, $sam);
    repositoryAssign('tenant_acme', $perm, $sam);

    $services = serviceRepository()->bookable([$perm, $colour, $cut, $cut + 100]);

    expect(array_keys($services))->toBe([$colour, $cut])
        ->and($services[$colour]->shape->durationMin)->toBe(90)
        ->and($services[$colour]->shape->bufferBeforeMin)->toBe(5)
        ->and($services[$colour]->shape->bufferAfterMin)->toBe(10)
        ->and($services[$colour]->processingGapMin)->toBe(30)
        ->and($services[$colour]->leadTimeMin)->toBe(120)
        ->and($services[$colour]->horizonDays)->toBe(14)
        ->and($services[$colour]->staffIds)->toBe([$sam])
        ->and($services[$colour]->price->minor)->toBe(8000)
        ->and($services[$colour]->price->currency->code)->toBe('EUR')
        ->and($services[$colour]->paymentMode)->toBe(PaymentMode::Online)
        ->and($services[$colour]->requiresApproval)->toBeTrue()
        ->and($services[$colour]->approvalHoldHours)->toBe(6)
        ->and($services[$colour]->onApprovalTimeout)->toBe(ApprovalTimeout::AutoApprove)
        ->and($services[$cut]->price->minor)->toBe(0)
        ->and($services[$cut]->paymentMode)->toBe(PaymentMode::Onsite)
        ->and($services[$cut]->requiresApproval)->toBeFalse()
        ->and($services[$cut]->approvalHoldHours)->toBeNull()
        ->and($services[$cut]->onApprovalTimeout)->toBe(ApprovalTimeout::Expire)
        ->and($services[$cut]->shape->durationMin)->toBe(45)
        ->and($services[$cut]->processingGapMin)->toBe(0)
        ->and($services[$cut]->leadTimeMin)->toBe(0)
        ->and($services[$cut]->horizonDays)->toBe(60)
        ->and($services[$cut]->staffIds)->toBe([]);
});

it('names a service\'s active assigned staff members with the implicit one among them, ascending, and never an inactive one', function () {
    provisionTenant('acme');
    $implicit = repositoryImplicitId('tenant_acme');
    $sam = repositoryStaff('tenant_acme', 'Sam');
    $gone = repositoryStaff('tenant_acme', 'Gone', ['status' => 'inactive']);
    $cut = repositoryService('tenant_acme', 'Cut');
    repositoryAssign('tenant_acme', $cut, $sam);
    repositoryAssign('tenant_acme', $cut, $gone);
    repositoryAssign('tenant_acme', $cut, $implicit);

    expect(serviceRepository()->bookable([$cut])[$cut]->staffIds)->toBe([$implicit, $sam]);
});

it('draws on the services and members offered online on the public road, the implicit member among them, and on every active one on the whole pool', function () {
    provisionTenant('acme');
    $implicit = repositoryImplicitId('tenant_acme');
    $sam = repositoryStaff('tenant_acme', 'Sam');
    $away = repositoryStaff('tenant_acme', 'Away', ['online' => false]);
    $cut = repositoryService('tenant_acme', 'Cut');
    $private = repositoryService('tenant_acme', 'Private fitting', ['online' => false]);
    repositoryAssign('tenant_acme', $cut, $implicit);
    repositoryAssign('tenant_acme', $cut, $sam);
    repositoryAssign('tenant_acme', $cut, $away);
    repositoryAssign('tenant_acme', $private, $sam);

    $online = serviceRepository(pool: Pool::Online)->bookable([$cut, $private]);
    $whole = serviceRepository(pool: Pool::Whole)->bookable([$cut, $private]);

    expect(array_keys($online))->toBe([$cut])
        ->and($online[$cut]->staffIds)->toBe([$implicit, $sam])
        ->and(array_keys($whole))->toBe([$cut, $private])
        ->and($whole[$cut]->staffIds)->toBe([$implicit, $sam, $away]);
});

it('answers nothing for no ids, without a statement, and nothing for ids no active service has', function () {
    provisionTenant('acme');
    repositoryService('tenant_acme', 'Perm', ['status' => 'inactive']);

    $repository = serviceRepository();
    DB::enableQueryLog();
    $none = $repository->bookable([]);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    expect($none)->toBe([])
        ->and($log)->toBe([])
        ->and(serviceRepository()->bookable([1, 2]))->toBe([]);
});

it('reads the tenant it was built for and no other, though each schema numbers its rows from one', function () {
    provisionTenant('acme');
    provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com']);
    $acmeCut = repositoryService('tenant_acme', 'Acme cut', ['duration_min' => 45]);
    $bravoCut = repositoryService('tenant_bravo', 'Bravo cut', ['duration_min' => 90]);
    $bravoSam = repositoryStaff('tenant_bravo', 'Bravo Sam');
    repositoryAssign('tenant_bravo', $bravoCut, $bravoSam);

    expect($bravoCut)->toBe($acmeCut)
        ->and(serviceRepository('acme')->bookable([$acmeCut])[$acmeCut]->shape->durationMin)->toBe(45)
        ->and(serviceRepository('acme')->bookable([$acmeCut])[$acmeCut]->staffIds)->toBe([])
        ->and(serviceRepository('bravo')->bookable([$bravoCut])[$bravoCut]->shape->durationMin)->toBe(90)
        ->and(serviceRepository('bravo')->bookable([$bravoCut])[$bravoCut]->staffIds)->toBe([$bravoSam]);
});

it('leaves the connection free of any search path change', function () {
    provisionTenant('acme');
    $cut = repositoryService('tenant_acme', 'Cut');

    serviceRepository()->bookable([$cut]);

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});
