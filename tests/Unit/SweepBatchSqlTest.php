<?php

use App\Application\ExpireHolds;
use App\Domain\Booking\TransitionColumn;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\BlockingItems;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\Db\PostgresSweepBatch;

/*
 * The four statements of the sweeper, spelled out. The feature and concurrency suites run
 * them; this file holds the text to what each one must do.
 */

it('guards the sweep with the transaction-scoped advisory lock in its two-integer form, which answers rather than waits', function () {
    expect(PostgresSweepBatch::claimSql())->toBe('select pg_try_advisory_xact_lock(?, ?) as taken')
        // Transaction-scoped, never session-scoped: a session lock leaks across pooled
        // connections and outlives the job that took it.
        ->and(PostgresSweepBatch::claimSql())->not->toContain('pg_advisory_lock')
        ->and(PostgresSweepBatch::claimSql())->not->toContain('pg_try_advisory_lock(')
        // Two plain integers, so nothing is hashed and no two tenants can collide.
        ->and(PostgresSweepBatch::claimSql())->not->toContain('hashtext');
});

it('selects the held bookings past their expiry, oldest id first, locked and skipping whatever a live booking transaction holds, five hundred at a time', function () {
    expect(PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH))->toBe(
        'select id, uuid::text as uuid, status, hold_class, on_approval_timeout, payment_mode from bookings'
        ." where status in ('pending', 'awaiting_approval', 'awaiting_payment')"
        .' and hold_expires_at <= now()'
        .' order by id for update skip locked'
        .' limit 500',
    );
});

it('reads the timeout policy without judging it, which is the one way its choice differs from the reap over the same rows', function () {
    // The reap may only free slots, so it must leave a request the clock granted alone. The sweep
    // is the only thing that decides one, so it selects it and lets the use case split the batch
    // in PHP, where the trail and the event are written too.
    expect(PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH))->toContain('hold_class, on_approval_timeout, payment_mode from bookings')
        ->and(PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH))->not->toContain("on_approval_timeout = 'auto_approve'")
        ->and(PostgresBookingWriter::reapBookingsSql())->toContain("on_approval_timeout = 'auto_approve'");
});

it('touches no staff-day: the sweep takes no mutex row and bumps no revision', function () {
    $statements = [
        PostgresSweepBatch::claimSql(),
        PostgresSweepBatch::lapsedSql(ExpireHolds::BATCH),
        PostgresSweepBatch::expireSql(),
        PostgresSweepBatch::confirmSql(TransitionColumn::cases()),
    ];

    foreach ($statements as $statement) {
        expect($statement)->not->toContain('staff_days')
            ->and($statement)->not->toContain('rev');
    }
});

it('expires a whole batch in one statement, by the ids the selection locked, and unblocks their items with the statement the reap uses', function () {
    expect(PostgresSweepBatch::expireSql())->toBe(
        "update bookings set status = 'expired', hold_class = null, hold_expires_at = null,"
        .' manage_token_sealed = null, updated_at = now() where id = any(?::bigint[])',
    )->and(BlockingItems::unblockSql())->toBe(
        'update booking_items set blocks = false, updated_at = now() where booking_id = any(?::bigint[])',
    );
});

it('writes the trail of a whole batch in one statement, by the ids it is already holding, and never one statement per booking', function () {
    expect(AuditRow::batchSql(1))->toBe(
        'insert into audit_log (booking_id, actor, action, payload) values (?, ?, ?, ?::jsonb)',
    )->and(AuditRow::batchSql(3))->toBe(
        'insert into audit_log (booking_id, actor, action, payload)'
        .' values (?, ?, ?, ?::jsonb), (?, ?, ?, ?::jsonb), (?, ?, ?, ?::jsonb)',
    )
        // By id, so the batch does not go back to the table to find out what it is holding.
        ->and(AuditRow::batchSql(1))->not->toContain('uuid')
        ->and(fn () => AuditRow::batchSql(0))->toThrow(InvalidArgumentException::class);
});

it('confirms a whole batch in one statement, writing the approval columns beside the status, each bound the way the compare-and-set binds it', function () {
    $columns = [
        TransitionColumn::HoldClass,
        TransitionColumn::HoldExpiresAt,
        TransitionColumn::ApprovedAt,
        TransitionColumn::ApprovedBy,
    ];

    expect(PostgresSweepBatch::confirmSql($columns))->toBe(
        "update bookings set status = 'confirmed', updated_at = now(),"
        .' hold_class = ?, hold_expires_at = ?::timestamptz, approved_at = ?::timestamptz, approved_by = ?'
        .' where id = any(?::bigint[])',
    );
});

it('ends the hold when it expires, the class and the expiry both, which is what every transition out of a held state does and what the reap does beside it', function () {
    // hold_class is null once settled. A row left carrying a class and a past expiry answers
    // GET /bookings/{uuid} with both, where a cancelled booking answers nulls, and the
    // booking app's countdown reads that field.
    expect(PostgresSweepBatch::expireSql())->toContain('hold_class = null, hold_expires_at = null')
        ->and(PostgresBookingWriter::reapBookingsSql())->toContain("set status = 'expired', hold_class = null, hold_expires_at = null");
});
