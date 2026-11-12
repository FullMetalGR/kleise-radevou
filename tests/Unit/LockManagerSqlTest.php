<?php

use App\Infrastructure\Db\LockManager;

it('spells the lock as one statement: every pair at revision zero, the composite key as the conflict target, the revision bumped on conflict, and staff, day and revision returned', function () {
    expect(LockManager::sql(1))->toBe(
        'insert into staff_days (staff_id, day, rev) values (?, ?, 0)'
        .' on conflict (staff_id, day) do update set rev = staff_days.rev + 1'
        .' returning staff_id, day, rev',
    )->and(LockManager::sql(3))->toBe(
        'insert into staff_days (staff_id, day, rev) values (?, ?, 0), (?, ?, 0), (?, ?, 0)'
        .' on conflict (staff_id, day) do update set rev = staff_days.rev + 1'
        .' returning staff_id, day, rev',
    );
});

it('has no form for zero rows', function () {
    expect(fn () => LockManager::sql(0))->toThrow(InvalidArgumentException::class);
});
