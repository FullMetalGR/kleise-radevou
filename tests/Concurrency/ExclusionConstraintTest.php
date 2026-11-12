<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The exclusion constraint and its boundary companions, on a tenant provisioned for real and
 * dropped afterwards. Nothing here is concurrent; the constraint is structural. It sits in this
 * suite because it counts among the mandatory concurrency tests, and this suite is the one that
 * may never be skipped.
 */

beforeEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
    provisionTenant('acme');
    itemService('tenant_acme');
});

afterEach(function () {
    DB::statement('drop schema if exists tenant_acme cascade');
});

function insertItem(int $staffId, string $start, string $end, bool $blocks = true): void
{
    $bookingId = DB::table('tenant_acme.bookings')->insertGetId(bookingRow());

    DB::table('tenant_acme.booking_items')->insert([...itemRow($bookingId, $staffId, $start, $end), 'blocks' => $blocks]);
}

it('raises 23P01 for a direct insert of an overlapping blocking item on the same staff member', function () {
    insertItem(1, '2026-10-01 09:00+00', '2026-10-01 10:00+00');

    expect(fn () => insertItem(1, '2026-10-01 09:30+00', '2026-10-01 10:30+00'))
        ->toThrow(QueryException::class, 'SQLSTATE[23P01]');

    expect(DB::table('tenant_acme.booking_items')->count())->toBe(1);
});

it('carries the SQLSTATE as the exception code, for the map that must never read a message', function () {
    insertItem(1, '2026-10-01 09:00+00', '2026-10-01 10:00+00');

    try {
        insertItem(1, '2026-10-01 09:00+00', '2026-10-01 10:00+00');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23P01');

        return;
    }

    $this->fail('No QueryException was thrown.');
});

it('accepts the same range on a different staff member', function () {
    // A second member to name: the staff key of migration 000013 refuses an item naming nobody.
    $second = DB::table('tenant_acme.staff')->insertGetId(['name' => 'Second Person', 'status' => 'active', 'sort' => 1]);
    insertItem(1, '2026-10-01 09:00+00', '2026-10-01 10:00+00');
    insertItem($second, '2026-10-01 09:00+00', '2026-10-01 10:00+00');

    expect(DB::table('tenant_acme.booking_items')->count())->toBe(2);
});

it('accepts an adjacent range sharing the half-open boundary', function () {
    insertItem(1, '2026-10-01 09:00+00', '2026-10-01 10:00+00');
    insertItem(1, '2026-10-01 10:00+00', '2026-10-01 11:00+00');
    insertItem(1, '2026-10-01 08:00+00', '2026-10-01 09:00+00');

    expect(DB::table('tenant_acme.booking_items')->count())->toBe(3);
});

it('accepts an overlap where one side does not block, whichever side it is', function () {
    insertItem(1, '2026-10-01 09:00+00', '2026-10-01 10:00+00', blocks: false);
    insertItem(1, '2026-10-01 09:30+00', '2026-10-01 10:30+00');
    insertItem(1, '2026-10-01 09:45+00', '2026-10-01 10:45+00', blocks: false);

    expect(DB::table('tenant_acme.booking_items')->count())->toBe(3);
});
