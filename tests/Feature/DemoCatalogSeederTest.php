<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/*
 * The demo catalog: what luna and pier hold after the seeder runs, on the tables and on the
 * wire, and that a second run changes no row. The seeder provisions through the console
 * command inside the transaction this suite wraps each test in, as the provisioning tests do.
 */

/**
 * Every service of a schema in sort order, each with the names of the staff assigned to it,
 * active or not, implicit or not: the table as it is, not as the wire shows it.
 *
 * @return array<string, array{status: string, staff: list<string>, processing_gap_min: int, requires_approval: bool, payment_mode: string, currency: string}>
 */
function catalogRows(string $schema): array
{
    $rows = [];

    foreach (DB::table($schema.'.services')->orderBy('sort')->get() as $service) {
        assert(is_int($service->id) && is_string($service->name) && is_string($service->status) && is_string($service->payment_mode));
        assert(is_int($service->processing_gap_min) && is_bool($service->requires_approval) && is_string($service->currency));

        /** @var list<string> $staff */
        $staff = DB::table($schema.'.service_staff', 'assignment')
            ->join($schema.'.staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->where('assignment.service_id', $service->id)
            ->orderBy('staff.sort')
            ->pluck('staff.name')
            ->all();

        $rows[$service->name] = [
            'status' => $service->status,
            'staff' => $staff,
            'processing_gap_min' => $service->processing_gap_min,
            'requires_approval' => $service->requires_approval,
            'payment_mode' => $service->payment_mode,
            'currency' => $service->currency,
        ];
    }

    return $rows;
}

/**
 * Every row of every table the seeder writes, as a snapshot a second run is held to.
 *
 * @return array<string, mixed>
 */
function demoSnapshot(): array
{
    $snapshot = [
        'tenants' => DB::table('tenants')->orderBy('id')->get()->all(),
        'users' => DB::table('users')->orderBy('id')->get()->all(),
    ];

    foreach (['tenant_luna', 'tenant_pier'] as $schema) {
        foreach (['settings', 'staff', 'services', 'service_staff', 'shift_patterns', 'shift_overrides', 'blackouts'] as $table) {
            $snapshot[$schema.'.'.$table] = DB::table($schema.'.'.$table)->orderBy('id')->get()->all();
        }
    }

    return $snapshot;
}

it('gives luna two named active staff members beside its implicit row', function () {
    seedDemo();

    $staff = DB::table('tenant_luna.staff')->orderBy('sort')->get(['name', 'status', 'is_implicit', 'sort'])->map(fn (stdClass $row): array => (array) $row)->all();

    expect($staff)->toBe([
        ['name' => 'Luna Hair Studio', 'status' => 'active', 'is_implicit' => true, 'sort' => 0],
        ['name' => 'Elena Papadaki', 'status' => 'active', 'is_implicit' => false, 'sort' => 1],
        ['name' => 'Nikos Georgiou', 'status' => 'active', 'is_implicit' => false, 'sort' => 2],
    ]);
});

it('gives luna four active services with the shapes the suite needs and one inactive service, all in its currency', function () {
    seedDemo();

    $catalog = catalogRows('tenant_luna');

    expect(array_keys($catalog))->toBe(['Haircut', 'Beard trim', 'Colour', 'Bridal trial', 'Perm'])
        ->and($catalog['Haircut']['staff'])->toBe(['Elena Papadaki', 'Nikos Georgiou'])
        ->and($catalog['Beard trim']['staff'])->toBe(['Nikos Georgiou'])
        ->and($catalog['Colour']['processing_gap_min'])->toBe(30)
        ->and($catalog['Bridal trial']['requires_approval'])->toBeTrue()
        ->and($catalog['Perm']['status'])->toBe('inactive')
        ->and(array_column($catalog, 'status'))->toBe(['active', 'active', 'active', 'active', 'inactive'])
        ->and(array_unique(array_column($catalog, 'currency')))->toBe(['EUR'])
        ->and(array_filter($catalog, fn (array $row): bool => $row['requires_approval']))->toHaveCount(1)
        ->and(array_filter($catalog, fn (array $row): bool => $row['processing_gap_min'] > 0))->toHaveCount(1);
});

it('assigns pier\'s services to its implicit member alone, in its currency', function () {
    seedDemo();

    $catalog = catalogRows('tenant_pier');

    expect(array_keys($catalog))->toBe(['Initial assessment', 'Follow-up session'])
        ->and(array_column($catalog, 'staff'))->toBe([['Pier Physiotherapy'], ['Pier Physiotherapy']])
        ->and(array_unique(array_column($catalog, 'currency')))->toBe(['GBP'])
        ->and(DB::table('tenant_pier.staff')->count())->toBe(1);
});

it('shows on the wire luna\'s four active services with their named staff, never Perm, and pier\'s with empty staff lists', function () {
    seedDemo();

    $this->getJson(tenantUrl('luna', '/api/v1/services'))->assertOk()
        ->assertJsonCount(4, 'services')
        ->assertDontSee('Perm')
        ->assertDontSee('Luna Hair Studio')
        ->assertJsonPath('services.*.name', ['Haircut', 'Beard trim', 'Colour', 'Bridal trial'])
        ->assertJsonPath('services.0.staff.*.name', ['Elena Papadaki', 'Nikos Georgiou'])
        ->assertJsonPath('services.1.staff.*.name', ['Nikos Georgiou'])
        ->assertJsonPath('services.2.staff.*.name', ['Elena Papadaki', 'Nikos Georgiou'])
        ->assertJsonPath('services.3.staff.*.name', ['Elena Papadaki'])
        ->assertJsonPath('services.*.currency', ['EUR', 'EUR', 'EUR', 'EUR']);

    $this->getJson(tenantUrl('pier', '/api/v1/services'))->assertOk()
        ->assertJsonCount(2, 'services')
        ->assertDontSee('Pier Physiotherapy')
        ->assertJsonPath('services.*.name', ['Initial assessment', 'Follow-up session'])
        ->assertJsonPath('services.*.staff', [[], []])
        ->assertJsonPath('services.*.currency', ['GBP', 'GBP']);
});

it('changes no row on a second run, and says so', function () {
    $first = seedDemo();
    $before = demoSnapshot();

    $second = seedDemo();

    expect($first)->toContain('Catalog of "luna": 2 staff, 5 services and 8 assignments added.')
        ->toContain('Catalog of "pier": 0 staff, 2 services and 2 assignments added.')
        ->and($second)->toContain('Catalog of "luna" is already in place; nothing changed.')
        ->toContain('Catalog of "pier" is already in place; nothing changed.')
        ->and(demoSnapshot())->toEqual($before);
});
