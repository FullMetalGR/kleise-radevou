<?php

use App\Infrastructure\Db\TransactionRunner;
use App\Models\User;
use App\Tenancy\Role;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

pest()->use(DatabaseTruncation::class);

/*
 * The session zone the pgsql connection pins: UTC, named in config/database.php and set by the
 * framework's connector on connect, so a value that crosses without an offset, a model-written
 * created_at and updated_at among them, is read as the true instant on any cluster, the
 * private one included, whose own zone is Europe/Athens.
 *
 * Not wrapped in a transaction: the tenant's case enters the transaction runner, which refuses
 * to open inside another.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_luna cascade');
    DB::table('users')->delete();
    DB::table('tenants')->delete();
});

it('reads UTC as the session zone on the default connection and inside a tenant\'s transaction on its schema', function () {
    $tenant = provisionTenant('luna')->tenant;

    $inside = app(TransactionRunner::class)->run($tenant, fn (Connection $connection): array => [
        $connection->scalar('select current_schema()'),
        $connection->scalar("select current_setting('TimeZone')"),
    ]);

    expect(DB::scalar("select current_setting('TimeZone')"))->toBe('UTC')
        ->and($inside)->toBe(['tenant_luna', 'UTC']);
});

it('stores a users row\'s created_at and updated_at written through the model as the instant the frozen clock read, spelled back with a zero offset', function () {
    $this->travelTo(new DateTimeImmutable('2026-11-02 06:00:00', new DateTimeZone('UTC')));
    $tenant = Tenant::query()->create(['slug' => 'luna', 'name' => 'Luna', 'timezone' => 'Europe/Athens', 'currency' => 'EUR']);

    $user = User::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Olga Owner',
        'email' => 'owner@luna.example',
        'password' => 'correct horse battery staple',
        'role' => Role::Owner,
    ]);

    expect(DB::scalar("select created_at::text || '/' || updated_at::text from users where id = ?", [$user->id]))
        ->toBe('2026-11-02 06:00:00+00/2026-11-02 06:00:00+00');
});
