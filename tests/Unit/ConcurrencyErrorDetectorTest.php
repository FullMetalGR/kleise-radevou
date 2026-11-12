<?php

use App\Infrastructure\Db\ConcurrencyErrorDetector;

/*
 * The detector the framework's transaction retry consults, matching by SQLSTATE and nothing
 * else. The framework's own detector lists 40001 by code and finds 40P01 only through the
 * English text "deadlock detected", which a cluster with a translated lc_messages never sends.
 */

it('recognises {sqlstate} by code, with a message no text match would recognise', function (string $sqlstate) {
    expect((new ConcurrencyErrorDetector)->causedByConcurrencyError(sqlError($sqlstate, 'Verklemmung entdeckt')))->toBeTrue();
})->with(['40001', '40P01']);

it('recognises the code through a domain exception wrapping the query exception', function () {
    $wrapped = new RuntimeException('hold failed', 0, sqlError('40P01', 'interblocage'));

    expect((new ConcurrencyErrorDetector)->causedByConcurrencyError($wrapped))->toBeTrue();
});

it('leaves {sqlstate} out, even when its message speaks of a deadlock', function (string $sqlstate) {
    expect((new ConcurrencyErrorDetector)->causedByConcurrencyError(sqlError($sqlstate, 'deadlock detected')))->toBeFalse();
})->with(['55P03', '57014', '23P01', '23505', '21000', '25P02', '22012']);

it('ignores an exception with no SQLSTATE, whatever its message says', function () {
    expect((new ConcurrencyErrorDetector)->causedByConcurrencyError(new RuntimeException('deadlock detected')))->toBeFalse();
});
