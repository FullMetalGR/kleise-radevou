<?php

use App\Models\User;
use App\Tenancy\InvalidSlug;
use App\Tenancy\Role;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

it('creates an active tenant on the floor that carries a timezone and a currency', function () {
    $tenant = Tenant::create([
        'slug' => 'acme',
        'name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
    ]);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->entitlements)->toBe([])
        ->and($tenant->narrowed)->toBe([])
        ->and($tenant->subscription_state)->toBe('active')
        ->and($tenant->timezone)->toBe('Europe/Athens')
        ->and($tenant->currency)->toBe('EUR')
        ->and($tenant->plan_ref)->toBeNull()
        ->and($tenant->grace_ends_at)->toBeNull()
        ->and($tenant->closing_at)->toBeNull();
});

it('carries the same defaults in memory as the table does', function () {
    $created = Tenant::factory()->create();
    $stored = $created->fresh();

    $columns = ['status', 'entitlements', 'narrowed', 'subscription_state', 'plan_ref', 'grace_ends_at', 'closing_at'];

    expect($stored)->not->toBeNull()
        ->and($created->only($columns))->toEqual($stored?->only($columns));
});

it('lowercases the slug and derives the schema name from it', function () {
    $tenant = Tenant::factory()->create(['slug' => 'Acme-Salon']);

    expect($tenant->slug)->toBe('acme-salon')
        ->and($tenant->schema_name)->toBe('tenant_acme_salon')
        ->and(DB::table('tenants')->where('id', $tenant->id)->value('schema_name'))->toBe('tenant_acme_salon');
});

it('takes a slug value object as well as a string', function () {
    $tenant = Tenant::factory()->create(['slug' => Slug::of('acme')]);

    expect($tenant->schema_name)->toBe('tenant_acme');
});

it('refuses a malformed slug before it reaches the table', function () {
    expect(fn () => Tenant::factory()->make(['slug' => 'acme_salon']))->toThrow(InvalidSlug::class);
});

it('discards a schema name offered as input', function () {
    $tenant = Tenant::create([
        'slug' => 'acme',
        'name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'schema_name' => 'public',
    ]);

    expect($tenant->schema_name)->toBe('tenant_acme');
});

it('refuses to have its schema name set by any dynamic path', function () {
    // A direct assignment is already a type error to the analyser; these are the paths it cannot
    // see.
    $tenant = Tenant::factory()->make();

    expect(fn () => $tenant->setAttribute('schema_name', 'public'))->toThrow(LogicException::class)
        ->and(fn () => $tenant->forceFill(['schema_name' => 'public']))->toThrow(LogicException::class)
        ->and(fn () => $tenant->fill(['schema_name' => 'public']))->not->toThrow(LogicException::class)
        ->and($tenant->schema_name)->not->toBe('public');
});

it('keeps its slug once created', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $tenant->slug = 'acme-2';

    expect(fn () => $tenant->save())->toThrow(LogicException::class)
        ->and($tenant->fresh()?->slug)->toBe('acme');
});

it('saves an unchanged slug alongside other edits', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);

    $tenant->slug = 'ACME';
    $tenant->name = 'Renamed';
    $tenant->save();

    expect($tenant->fresh()?->name)->toBe('Renamed');
});

it('reads the entitlement set as a list of slugs', function () {
    $tenant = Tenant::factory()->create();

    DB::table('tenants')->where('id', $tenant->id)->update(['entitlements' => '{staff}']);

    expect($tenant->fresh()?->entitlements)->toBe(['staff']);
});

it('writes the entitlement set as a PostgreSQL array', function () {
    $tenant = Tenant::factory()->create();

    $tenant->forceFill(['entitlements' => ['staff'], 'narrowed' => []])->save();

    expect(DB::table('tenants')->where('id', $tenant->id)->value('entitlements'))->toBe('{staff}')
        ->and(DB::table('tenants')->where('id', $tenant->id)->value('narrowed'))->toBe('{}');
});

it('keeps the billing columns out of mass assignment', function () {
    $tenant = Tenant::create([
        'slug' => 'acme',
        'name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'entitlements' => ['staff'],
        'status' => 'closing',
    ]);

    expect($tenant->fresh()?->entitlements)->toBe([])
        ->and($tenant->fresh()?->status)->toBe(TenantStatus::Active);
});

it('has users that belong to it', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create();

    expect($user->tenant?->is($tenant))->toBeTrue()
        ->and($tenant->users()->count())->toBe(1);
});

it('gives a factory user a tenant and the owner role', function () {
    $user = User::factory()->create();

    expect($user->tenant)->toBeInstanceOf(Tenant::class)
        ->and($user->role)->toBe(Role::Owner);
});
