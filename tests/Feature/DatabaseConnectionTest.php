<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

it('runs against PostgreSQL 16 or later', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    expect(DB::scalar("select current_setting('server_version_num')::int"))
        ->toBeGreaterThanOrEqual(160000);
});

it('has the citext and btree_gist extensions available', function () {
    $available = DB::table('pg_available_extensions')
        ->whereIn('name', ['btree_gist', 'citext'])
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($available)->toBe(['btree_gist', 'citext']);
});
