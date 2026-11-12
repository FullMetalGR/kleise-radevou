<?php

use Illuminate\Support\Facades\DB;

it('never wraps a concurrency test in a transaction', function () {
    expect(DB::transactionLevel())->toBe(0);
});

it('starts every test from truncated tables', function (string $key) {
    DB::table('cache')->insert(['key' => $key, 'value' => 'x', 'expiration' => 2147483647]);

    expect(DB::table('cache')->count())->toBe(1);
})->with(['first', 'second']);
