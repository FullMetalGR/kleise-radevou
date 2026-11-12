<?php

use App\Tenancy\Tenant;
use App\Tenancy\TenantMigrator;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantStatus;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/*
 * tenant:migrate, the way a built schema takes a tenant migration added after its build: every
 * tenant whose row is not provisioning, one schema at a time, each in its own transaction, one
 * line per schema, and a stop at the first failure with that schema rolled back.
 *
 * The migration it runs is a throwaway this file writes under a temporary database path and
 * removes, never one left in database/migrations/tenant: the tenants are built first, on the
 * real set, and the database path is pointed at the temporary one only for the command, so the
 * migrator finds the probe pending in every schema and finds nothing else there. A built schema's
 * ran list names the real set, which the migrator does not need to see on disk to skip.
 *
 * Wrapped in a transaction: the command enters no transaction runner, its own per-schema
 * transaction nests as a savepoint, and a savepoint rolled back is the same "as it was" the
 * command's own rollback gives outside a test. Every schema built here rolls back with the test.
 */

/** The two throwaway migrations, in the order they run: a table each, the second the one that can fail. */
const MIGRATE_PROBES = ['2099_01_01_000000_create_migrate_probe_table', '2099_01_01_000001_create_migrate_probe_two_table'];

/** What a schema reports when it ran both. */
const MIGRATE_RAN = 'ran 2099_01_01_000000_create_migrate_probe_table, 2099_01_01_000001_create_migrate_probe_two_table';

beforeEach(function () {
    $this->probeRoot = sys_get_temp_dir().'/tenant-migrate-'.bin2hex(random_bytes(6));
});

afterEach(function () {
    $directory = $this->probeRoot.'/migrations/tenant';

    foreach (glob($directory.'/*.php') ?: [] as $file) {
        unlink($file);
    }

    foreach ([$directory, $this->probeRoot.'/migrations', $this->probeRoot] as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
});

/**
 * Writes the two throwaway migrations and points the database path at them: each creates a
 * table in the schema the search path names, and where a schema is given the second throws in
 * that schema alone after its table is made. A failure there has two migrations' DDL to undo, the
 * first committed to nothing but the run's own transaction for that schema, which is what a
 * per-schema rollback is and a per-migration one is not.
 */
function probeMigration(string $root, ?string $failsIn = null): void
{
    $directory = $root.'/migrations/tenant';
    mkdir($directory, 0777, true);

    foreach ([[MIGRATE_PROBES[0], 'migrate_probe', null], [MIGRATE_PROBES[1], 'migrate_probe_two', $failsIn]] as [$name, $table, $refuses]) {
        $failure = $refuses === null ? '' : sprintf(
            "\n        if (DB::scalar('select current_schema()') === '%s') {\n            throw new RuntimeException('The probe refuses this schema.');\n        }\n",
            $refuses,
        );

        file_put_contents($directory.'/'.$name.'.php', <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                });
        {$failure}
            }
        };

        PHP);
    }

    app()->useDatabasePath($root);

    expect(TenantMigrator::path())->toBe($directory);
}

/**
 * @param  array<string, mixed>  $options
 * @return array{int, list<string>}
 */
function migrateTenants(array $options = []): array
{
    $code = Artisan::call('tenant:migrate', $options);
    $lines = array_values(array_filter(array_map(trim(...), explode("\n", Artisan::output())), fn (string $line): bool => $line !== ''));

    return [$code, $lines];
}

/**
 * The probe tables a schema holds, of the two the throwaway migrations create.
 *
 * @return list<string>
 */
function probed(string $schema): array
{
    /** @var list<string> */
    return DB::table('pg_tables')->where('schemaname', $schema)->whereIn('tablename', ['migrate_probe', 'migrate_probe_two'])->orderBy('tablename')->pluck('tablename')->all();
}

/**
 * @return list<string>
 */
function ranIn(string $schema): array
{
    /** @var list<string> */
    return DB::table($schema.'.migrations')->orderBy('id')->pluck('migration')->all();
}

/**
 * Three built tenants, in the order the command walks them, which is the order of their ids.
 *
 * @return list<Tenant>
 */
function threeBuilt(): array
{
    return [
        provisionTenant('acme', ['ownerEmail' => 'owner@acme.example'])->tenant,
        provisionTenant('bravo', ['ownerEmail' => 'owner@bravo.example'])->tenant,
        provisionTenant('carta', ['ownerEmail' => 'owner@carta.example'])->tenant,
    ];
}

it('runs a new tenant migration in every built schema, one line per schema naming what ran, and run again reports every schema up to date and changes nothing', function () {
    threeBuilt();
    $before = ranIn('tenant_acme');
    probeMigration($this->probeRoot);

    [$code, $lines] = migrateTenants();

    expect($code)->toBe(Command::SUCCESS)
        ->and($lines)->toBe([
            'tenant_acme: '.MIGRATE_RAN,
            'tenant_bravo: '.MIGRATE_RAN,
            'tenant_carta: '.MIGRATE_RAN,
        ]);

    foreach (['tenant_acme', 'tenant_bravo', 'tenant_carta'] as $schema) {
        expect(probed($schema))->toBe(['migrate_probe', 'migrate_probe_two'])
            ->and(ranIn($schema))->toBe([...$before, ...MIGRATE_PROBES]);
    }

    $ran = array_map(ranIn(...), ['tenant_acme', 'tenant_bravo', 'tenant_carta']);

    [$again, $lines] = migrateTenants();

    expect($again)->toBe(Command::SUCCESS)
        ->and($lines)->toBe([
            'tenant_acme: up to date',
            'tenant_bravo: up to date',
            'tenant_carta: up to date',
        ])
        ->and(array_map(ranIn(...), ['tenant_acme', 'tenant_bravo', 'tenant_carta']))->toBe($ran);
});

it('reports a schema the build left current as up to date, running nothing', function () {
    provisionTenant('acme', ['ownerEmail' => 'owner@acme.example']);
    $before = ranIn('tenant_acme');

    [$code, $lines] = migrateTenants();

    expect($code)->toBe(Command::SUCCESS)
        ->and($lines)->toBe(['tenant_acme: up to date'])
        ->and(ranIn('tenant_acme'))->toBe($before);
});

it('runs the one schema --slug names and no other', function () {
    threeBuilt();
    probeMigration($this->probeRoot);

    [$code, $lines] = migrateTenants(['--slug' => 'bravo']);

    expect($code)->toBe(Command::SUCCESS)
        ->and($lines)->toBe(['tenant_bravo: '.MIGRATE_RAN])
        ->and(probed('tenant_acme'))->toBe([])
        ->and(probed('tenant_bravo'))->toBe(['migrate_probe', 'migrate_probe_two'])
        ->and(probed('tenant_carta'))->toBe([]);
});

it('stops at the first schema whose migration fails, names it, and leaves it as it was, the schemas before it migrated and the ones after it untouched', function () {
    threeBuilt();
    $before = ranIn('tenant_bravo');
    probeMigration($this->probeRoot, failsIn: 'tenant_bravo');

    [$code, $lines] = migrateTenants();

    expect($code)->toBe(Command::FAILURE)
        ->and($lines)->toHaveCount(2)
        ->and($lines[0] ?? null)->toBe('tenant_acme: '.MIGRATE_RAN)
        ->and($lines[1] ?? '')->toStartWith('tenant_bravo: failed, rolled back, and the run stopped here: ')
        ->and($lines[1] ?? '')->toContain('The probe refuses this schema.')
        ->and(probed('tenant_acme'))->toBe(['migrate_probe', 'migrate_probe_two'])
        ->and(probed('tenant_bravo'))->toBe([])
        ->and(ranIn('tenant_bravo'))->toBe($before)
        ->and(probed('tenant_carta'))->toBe([])
        ->and(ranIn('tenant_carta'))->toBe($before);
});

it('skips a row still provisioning, which has no schema to migrate, and migrates a suspended and a closing tenant like an active one', function () {
    [$acme, $bravo] = threeBuilt();
    Tenant::query()->whereKey($acme->id)->update(['status' => TenantStatus::Suspended->value]);
    Tenant::query()->whereKey($bravo->id)->update(['status' => TenantStatus::Closing->value]);
    $reserved = app(TenantProvisioner::class)->reserve(signupFor('delta', ['ownerEmail' => 'owner@delta.example']));
    probeMigration($this->probeRoot);

    [$code, $lines] = migrateTenants();

    expect($code)->toBe(Command::SUCCESS)
        ->and($reserved->status)->toBe(TenantStatus::Provisioning)
        ->and($lines)->toBe([
            'tenant_acme: '.MIGRATE_RAN,
            'tenant_bravo: '.MIGRATE_RAN,
            'tenant_carta: '.MIGRATE_RAN,
        ])
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_delta')->exists())->toBeFalse();

    [$one, $refused] = migrateTenants(['--slug' => 'delta']);

    expect($one)->toBe(Command::INVALID)
        ->and($refused)->toBe(['No built tenant has the slug "delta"; a row still provisioning takes every migration when it is built.'])
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_delta')->exists())->toBeFalse();
});

it('skips a closed row, whose schema the drop removed, and migrates the tenants either side of it', function () {
    [, $bravo] = threeBuilt();
    $closed = writeClosed('bravo');
    probeMigration($this->probeRoot);

    [$code, $lines] = migrateTenants();

    expect($closed)->toBe($bravo->id)
        ->and($code)->toBe(Command::SUCCESS)
        ->and($lines)->toBe([
            'tenant_acme: '.MIGRATE_RAN,
            'tenant_carta: '.MIGRATE_RAN,
        ])
        ->and(DB::table('pg_namespace')->where('nspname', 'tenant_bravo')->exists())->toBeFalse();
});

it('refuses a slug no tenant holds, running nothing', function () {
    provisionTenant('acme', ['ownerEmail' => 'owner@acme.example']);
    probeMigration($this->probeRoot);

    [$code, $lines] = migrateTenants(['--slug' => 'nobody']);

    expect($code)->toBe(Command::INVALID)
        ->and($lines)->toBe(['No built tenant has the slug "nobody"; a row still provisioning takes every migration when it is built.'])
        ->and(probed('tenant_acme'))->toBe([]);
});

it('is registered and listed, and scheduled nowhere', function () {
    expect(Artisan::all())->toHaveKey('tenant:migrate');

    Artisan::call('list');

    expect(Artisan::output())->toContain('tenant:migrate');

    $scheduled = array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains($event->command ?? '', 'tenant:migrate'),
    );

    expect($scheduled)->toBe([]);
});
