<?php

use App\Domain\Booking\LockKey;
use App\Domain\Booking\Revisions;

it('answers the revision of every staff-day it was given, keyed by pair', function () {
    $revisions = new Revisions([
        [new LockKey(1, '2026-10-01'), 4],
        [new LockKey(1, '2026-10-02'), 0],
    ]);

    expect($revisions->of(new LockKey(1, '2026-10-01')))->toBe(4)
        ->and($revisions->of(new LockKey(1, '2026-10-02')))->toBe(0)
        ->and($revisions->has(new LockKey(1, '2026-10-02')))->toBeTrue()
        ->and($revisions->has(new LockKey(2, '2026-10-02')))->toBeFalse()
        ->and($revisions->toArray())->toBe(['1:2026-10-01' => 4, '1:2026-10-02' => 0])
        ->and(count($revisions))->toBe(2);
});

it('refuses to answer for a staff-day it does not hold', function () {
    $revisions = new Revisions([[new LockKey(1, '2026-10-01'), 4]]);

    expect(fn () => $revisions->of(new LockKey(1, '2026-10-02')))->toThrow(OutOfBoundsException::class);
});

it('refuses a staff-day given twice and a negative revision', function () {
    expect(fn () => new Revisions([[new LockKey(1, '2026-10-01'), 1], [new LockKey(1, '2026-10-01'), 2]]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Revisions([[new LockKey(1, '2026-10-01'), -1]]))
        ->toThrow(InvalidArgumentException::class);
});
