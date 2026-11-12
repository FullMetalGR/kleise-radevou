<?php

use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

/*
 * GET /api/v1/config on the wire: the seven keys, where the granularity and the important info come from, the silence
 * about packaging, and the cross-origin answer the embed depends on. Like the hosted page it
 * opens no transaction, so it is tested here inside the one this suite wraps each test in.
 */

function configUrl(string $slug): string
{
    return tenantUrl($slug, '/api/v1/config');
}

it('answers exactly seven keys: the tenant name, timezone, currency, granularity, important info, default language and language, and no eighth', function () {
    provisionTenant('acme', ['name' => 'Acme Salon', 'timezone' => 'Europe/Athens', 'currency' => 'EUR']);

    $response = $this->getJson(configUrl('acme'))->assertOk()->assertExactJson([
        'tenant_name' => 'Acme Salon',
        'timezone' => 'Europe/Athens',
        'currency' => 'EUR',
        'granularity_min' => 5,
        'important_info' => null,
        'default_language' => 'en',
        'language' => 'en',
    ]);

    $keys = array_keys((array) $response->json());

    expect($keys)->toHaveCount(7)->toBe(['tenant_name', 'timezone', 'currency', 'granularity_min', 'important_info', 'default_language', 'language']);
});

it('takes the important info from that tenant\'s settings row, null where the owner left it empty', function () {
    provisionTenant('acme');
    provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com']);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['important_info' => 'Please arrive five minutes early.']);

    $this->getJson(configUrl('acme'))->assertOk()->assertJsonPath('important_info', 'Please arrive five minutes early.');
    $this->getJson(configUrl('bravo'))->assertOk()->assertJsonPath('important_info', null);
});

it('takes the granularity from that tenant\'s settings row', function () {
    provisionTenant('acme');
    provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com']);
    DB::table('tenant_acme.settings')->where('id', 1)->update(['granularity_min' => 15]);

    $this->getJson(configUrl('acme'))->assertOk()->assertJsonPath('granularity_min', 15);
    $this->getJson(configUrl('bravo'))->assertOk()->assertJsonPath('granularity_min', 5);
});

it('says nothing about packaging, whatever the tenant bought', function () {
    $tenant = provisionTenant('acme')->tenant;
    DB::table('tenants')->where('id', $tenant->id)->update(['entitlements' => '{staff}', 'plan_ref' => 'price_123']);

    $this->getJson(configUrl('acme'))->assertOk()
        ->assertJsonMissingPath('entitlements')
        ->assertJsonMissingPath('plan_ref')
        ->assertDontSee('staff')
        ->assertDontSee('price_123');
});

it('answers a cross-origin read with permissive CORS', function () {
    provisionTenant('acme');

    $this->getJson(configUrl('acme'), ['Origin' => 'https://www.salon-x.example'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJsonPath('tenant_name', 'Acme Salon');
});

it('answers a cross-origin preflight with permissive CORS', function () {
    provisionTenant('acme');

    $this->call('OPTIONS', configUrl('acme'), server: [
        'HTTP_ORIGIN' => 'https://www.salon-x.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'GET')
        ->assertHeader('Access-Control-Allow-Headers', 'content-type');
});

it('does not open the hosted page to cross-origin reads: CORS is the API\'s', function () {
    provisionTenant('acme');

    $this->get(hostedPage('acme'), ['Origin' => 'https://www.salon-x.example'])
        ->assertOk()
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('answers 404 at an unknown subdomain, as JSON', function () {
    $this->getJson(configUrl('nobody'))->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

it('serves a suspended tenant the static page', function () {
    schemaBehind(Tenant::factory()->create(['slug' => 'paused', 'status' => TenantStatus::Suspended]));

    $this->get(configUrl('paused'))->assertStatus(503)->assertSee(pageSentence('pages.suspended.sentence'));
});

it('leaves the connection free of any search path change', function () {
    provisionTenant('acme');

    $this->getJson(configUrl('acme'))->assertOk();

    expect(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});
