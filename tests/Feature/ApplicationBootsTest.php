<?php

use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('answers the health check on the central host', function () {
    $this->get(centralUrl('/up'))->assertOk()->assertExactJson(['status' => 'up']);
});

it('does not answer the health check on a tenant host, an active tenant\'s or a suspended one\'s', function () {
    Tenant::factory()->create(['slug' => 'acme']);
    Tenant::factory()->create(['slug' => 'paused', 'status' => TenantStatus::Suspended]);

    $this->get(tenantUrl('acme', '/up'))->assertNotFound();
    $this->get(tenantUrl('paused', '/up'))->assertNotFound();
});
