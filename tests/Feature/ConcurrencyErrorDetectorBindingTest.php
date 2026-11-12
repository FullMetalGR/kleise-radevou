<?php

use App\Infrastructure\Db\ConcurrencyErrorDetector;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The binding the framework's transaction retry consults, and proof that it does. Not wrapped in
 * a transaction: at depth the framework never retries, so a wrapped version of these tests would
 * observe nothing.
 */

it('resolves the framework detector contract to the SQLSTATE detector', function () {
    expect(app(ConcurrencyErrorDetectorContract::class)::class)->toBe(ConcurrencyErrorDetector::class);
});

it('retries a 40P01 through the framework transaction loop on the code alone, with a message no text match would recognise', function () {
    $runs = 0;

    expect(function () use (&$runs): void {
        DB::transaction(function () use (&$runs): void {
            $runs++;

            throw sqlError('40P01', 'Verklemmung entdeckt');
        }, 3);
    })->toThrow(QueryException::class, 'Verklemmung');

    expect($runs)->toBe(3)
        ->and(DB::transactionLevel())->toBe(0);
});

it('does not retry a 55P03 through the framework loop, even when its message speaks of a deadlock', function () {
    $runs = 0;

    expect(function () use (&$runs): void {
        DB::transaction(function () use (&$runs): void {
            $runs++;

            throw sqlError('55P03', 'deadlock detected');
        }, 3);
    })->toThrow(QueryException::class);

    expect($runs)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);
});
