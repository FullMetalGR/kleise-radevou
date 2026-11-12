<?php

use App\Tenancy\Tenant;
use App\Tenancy\TenantStatus;
use Illuminate\Support\Facades\DB;

/*
 * Tests search_path correctness across many sequential requests, driven through the probe
 * route, so the real router, the real middleware and the real runner are all on the path, on
 * the one connection this process holds.
 *
 * The connection is whatever the environment names. With DB_URL set, the framework takes host,
 * port, database and credentials from it and phpunit.xml's defaults step aside, so this file
 * runs unchanged against another topology:
 *
 *     DB_URL=pgsql://user:secret@pooler.internal:6432/kleise_radevou composer test:concurrency
 *
 * That run is the check against the real pooling topology.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::statement('drop schema if exists tenant_bravo cascade');
    provisionTenant('acme');
    provisionTenant('bravo', ['ownerEmail' => 'bravo@example.com']);
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    DB::statement('drop schema if exists tenant_bravo cascade');
});

it('answers current_schema() through the runner, and the answer is the schema of the tenant the host names', function () {
    $this->get(tenantUrl('acme', '/probe'))->assertOk()->assertExactJson(['slug' => 'acme', 'schema' => 'tenant_acme']);
    $this->get(tenantUrl('bravo', '/probe'))->assertOk()->assertExactJson(['slug' => 'bravo', 'schema' => 'tenant_bravo']);
});

it('resolves a closing tenant and answers its schema, since it may reopen', function () {
    Tenant::query()->where('slug', 'acme')->update(['status' => TenantStatus::Closing->value]);

    $this->get(tenantUrl('acme', '/probe'))->assertOk()->assertExactJson(['slug' => 'acme', 'schema' => 'tenant_acme']);
});

it('keeps hundreds of sequential requests, alternating between two tenants on one connection, each on the schema its host named (test 17)', function () {
    $url = getenv('DB_URL');

    if (is_string($url) && $url !== '') {
        $named = parse_url($url);

        expect($named)->toBeArray()
            ->and(DB::connection()->getConfig('host'))->toBe($named['host'] ?? null)
            ->and(DB::connection()->getDatabaseName())->toBe(ltrim((string) ($named['path'] ?? ''), '/'));
    }

    $pdo = DB::connection()->getPdo();
    $requests = 300;
    $wrong = [];

    for ($i = 0; $i < $requests; $i++) {
        $slug = $i % 2 === 0 ? 'acme' : 'bravo';
        $response = $this->get(tenantUrl($slug, '/probe'));
        $answer = [$response->status(), $response->json('slug'), $response->json('schema')];

        if ($answer !== [200, $slug, 'tenant_'.$slug]) {
            $wrong[$i] = $answer;
        }
    }

    expect($wrong)->toBe([])
        ->and(DB::connection()->getPdo())->toBe($pdo)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::scalar("select current_setting('search_path')"))->toBe('public')
        ->and(DB::scalar('select current_schema()'))->toBe('public');
});
