<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantMigration;

/*
 * The tenant migration lock rule against a real reader: a tenant migration taking ACCESS
 * EXCLUSIVE gives up after ExclusiveStep's lock timeout behind an open transaction that has read
 * its table, and tenant:migrate stops with that schema named and rolled back, rather than wait
 * for as long as the reader lasts with the schema's bookings queued behind it; once the reader
 * ends, the same run lands. The reader is a second connection holding ACCESS SHARE in a
 * transaction left open. The run's own session carries a statement timeout as a bound, so a
 * migration without the rule fails on that bound, and on the assertion naming the lock timeout,
 * instead of hanging the suite.
 *
 * On acme, provisioned for real and dropped afterwards, taken back to before the migration under
 * trial through its own down().
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    $default = config('database.default');
    assert(is_string($default));
    config()->set('database.connections.migration_reader', config('database.connections.'.$default));
});

afterEach(function () {
    $reader = DB::connection('migration_reader');

    while ($reader->transactionLevel() > 0) {
        $reader->rollBack();
    }

    DB::purge('migration_reader');
    DB::statement('reset statement_timeout');
    DB::statement('drop schema if exists tenant_acme cascade');
});

it('stops {name} at the lock timeout behind an open reader of {table}, the schema rolled back and named, and lands it once the reader ends', function (string $name, string $table, string $after = '') {
    // A migration a later one depends on is taken back after that one, which lands beside it.
    if ($after !== '') {
        TenantMigration::takeBack('tenant_acme', $after);
    }

    TenantMigration::takeBack('tenant_acme', $name);
    $reader = DB::connection('migration_reader');
    $reader->beginTransaction();
    $reader->select(sprintf('select count(*) from tenant_acme.%s', $table));
    DB::statement("set statement_timeout = '10s'");

    $started = microtime(true);
    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);
    $waited = microtime(true) - $started;
    $output = trim(Artisan::output());

    expect(ExclusiveStep::LOCK_TIMEOUT)->toBe('1s')
        ->and($code)->toBe(1)
        ->and($output)->toStartWith('tenant_acme: failed, rolled back, and the run stopped here: ')
        ->and($output)->toContain('55P03')
        ->and($waited)->toBeGreaterThan(0.9)->toBeLessThan(5.0)
        ->and(DB::table('tenant_acme.migrations')->where('migration', $name)->exists())->toBeFalse()
        ->and(DB::scalar("select current_setting('lock_timeout')"))->toBe('0');

    $reader->rollBack();

    $again = Artisan::call('tenant:migrate', ['--slug' => 'acme']);

    expect($again)->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.$name.($after === '' ? '' : ', '.$after))
        ->and(DB::table('tenant_acme.migrations')->where('migration', $name)->exists())->toBeTrue();
})->with([
    '000026 on audit_log' => [TenantMigration::SUPPORT_ACTOR, 'audit_log'],
    '000027 on settings' => [TenantMigration::BOUNDS_AND_LINK, 'settings'],
    '000027 on services' => [TenantMigration::BOUNDS_AND_LINK, 'services'],
    '000028 on bookings' => [TenantMigration::ERASABLE_CUSTOMER, 'bookings'],
    '000029 on settings' => [TenantMigration::LANGUAGES, 'settings'],
    '000029 on bookings' => [TenantMigration::LANGUAGES, 'bookings'],
    '000031 on settings' => [TenantMigration::COLOUR_BY, 'settings'],
    // The table its own down() put back, which nothing but such a reader opens.
    '000032 on availability_rules' => [TenantMigration::RULES_DROPPED, 'availability_rules'],
    // The table the dropped key named, whose key triggers go with it.
    '000032 on staff' => [TenantMigration::RULES_DROPPED, 'staff'],
    // 000033 reads the accent column 000040 drops, so 000040 is taken back first.
    '000033 on settings' => [TenantMigration::THEME, 'settings', TenantMigration::ACCENT_DROPPED],
    '000034 on staff' => [TenantMigration::STAFF_COLOUR, 'staff'],
    '000040 on settings' => [TenantMigration::ACCENT_DROPPED, 'settings'],
]);

it('stops 000030 at the lock timeout behind an open writer of staff, whose foreign keys it takes SHARE ROW EXCLUSIVE on, and lands it once the writer ends', function () {
    // The schema as it stood before 000030: 000032 taken back first, its down() writing the
    // weekday rules 000030's copy reads.
    TenantMigration::takeBack('tenant_acme', TenantMigration::RULES_DROPPED);
    TenantMigration::takeBack('tenant_acme', TenantMigration::SHIFTS);
    $reader = DB::connection('migration_reader');
    $reader->beginTransaction();
    $reader->update('update tenant_acme.staff set updated_at = now() where is_implicit');
    DB::statement("set statement_timeout = '10s'");

    $started = microtime(true);
    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);
    $waited = microtime(true) - $started;
    $output = trim(Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toStartWith('tenant_acme: failed, rolled back, and the run stopped here: ')
        ->and($output)->toContain('55P03')
        ->and($waited)->toBeGreaterThan(0.9)->toBeLessThan(5.0)
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::SHIFTS)->exists())->toBeFalse();

    $reader->rollBack();

    expect(Artisan::call('tenant:migrate', ['--slug' => 'acme']))->toBe(0)
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::SHIFTS)->exists())->toBeTrue();
});

it('stops 000041 at the lock timeout behind an open writer of audit_log, whose index build takes SHARE on the trail, and lands it once the writer ends', function () {
    TenantMigration::takeBack('tenant_acme', TenantMigration::TRAIL_BY_TIME);
    $writer = DB::connection('migration_reader');
    $writer->beginTransaction();
    $writer->insert("insert into tenant_acme.audit_log (booking_id, actor, action) values (1, 'system', 'held')");
    DB::statement("set statement_timeout = '10s'");

    $started = microtime(true);
    $code = Artisan::call('tenant:migrate', ['--slug' => 'acme']);
    $waited = microtime(true) - $started;
    $output = trim(Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toStartWith('tenant_acme: failed, rolled back, and the run stopped here: ')
        ->and($output)->toContain('55P03')
        ->and($waited)->toBeGreaterThan(0.9)->toBeLessThan(5.0)
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::TRAIL_BY_TIME)->exists())->toBeFalse();

    $writer->rollBack();

    expect(Artisan::call('tenant:migrate', ['--slug' => 'acme']))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tenant_acme: ran '.TenantMigration::TRAIL_BY_TIME)
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::TRAIL_BY_TIME)->exists())->toBeTrue();
});

it('gives up the down of 000030 at the lock timeout behind an open reader of {table}, both tables left standing, and takes them once the reader ends', function (string $table) {
    $reader = DB::connection('migration_reader');
    $reader->beginTransaction();
    $reader->select(sprintf('select count(*) from tenant_acme.%s', $table));
    DB::statement("set statement_timeout = '10s'");

    $started = microtime(true);

    expect(fn () => TenantMigration::takeBack('tenant_acme', TenantMigration::SHIFTS))->toThrow(QueryException::class, '55P03');

    $waited = microtime(true) - $started;

    expect($waited)->toBeGreaterThan(0.9)->toBeLessThan(5.0)
        ->and(DB::scalar("select to_regclass('tenant_acme.shift_patterns')::text"))->toBe('tenant_acme.shift_patterns')
        ->and(DB::scalar("select to_regclass('tenant_acme.shift_overrides')::text"))->toBe('tenant_acme.shift_overrides')
        ->and(DB::table('tenant_acme.migrations')->where('migration', TenantMigration::SHIFTS)->exists())->toBeTrue();

    $reader->rollBack();
    TenantMigration::takeBack('tenant_acme', TenantMigration::SHIFTS);

    expect(DB::scalar("select to_regclass('tenant_acme.shift_patterns')::text"))->toBeNull();
})->with(['shift_patterns', 'shift_overrides']);
