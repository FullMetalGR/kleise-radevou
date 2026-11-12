<?php

use App\Application\Dto\ServerNowPlus;
use App\Domain\Booking\TransitionColumn;
use App\Infrastructure\Db\AuditRow;
use App\Infrastructure\Db\BlockingItems;
use App\Infrastructure\Db\LockedAvailability;
use App\Infrastructure\Db\PostgresBookingWriter;
use App\Infrastructure\Db\WrittenColumns;

/*
 * The SQL of steps 4, 5 and 6 of the insert protocol, spelled out, the one statement the
 * availability step adds, and the four a guarded transition puts beside them. The concurrency
 * and feature suites run it; this file holds the text to what each statement must do.
 */

it('reaps with two statements: choose the held bookings with a blocking item on the locked staff in the window whose expiry has passed, an auto-approving request excepted, lock that choice, expire it under the same conditions again and hand back what the update changed, then unblock their items', function () {
    expect(PostgresBookingWriter::reapBookingsSql())->toBe(
        'with doomed as (select id, uuid::text as uuid, status, hold_class from bookings'
        .' where id in (select booking_id from booking_items'
        ." where staff_id = any(?::bigint[]) and blocks and block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)'))"
        ." and status in ('pending', 'awaiting_approval', 'awaiting_payment')"
        .' and hold_expires_at <= now()'
        ." and not (status = 'awaiting_approval' and on_approval_timeout = 'auto_approve')"
        .' order by id for update)'
        .", expired as (update bookings set status = 'expired',"
        .' hold_class = null, hold_expires_at = null, manage_token_sealed = null, updated_at = now()'
        .' from doomed where bookings.id = doomed.id'
        ." and bookings.status in ('pending', 'awaiting_approval', 'awaiting_payment')"
        .' and bookings.hold_expires_at <= now()'
        ." and not (bookings.status = 'awaiting_approval' and bookings.on_approval_timeout = 'auto_approve')"
        .' returning bookings.id, doomed.uuid, doomed.status, doomed.hold_class)'
        .' select id, uuid, status, hold_class from expired order by id',
    )->and(BlockingItems::unblockSql())->toBe(
        'update booking_items set blocks = false, updated_at = now() where booking_id = any(?::bigint[])',
    );
});

it('carries the status, expiry and auto-approve conditions on the update itself, not on the choice alone, because read committed re-checks a statement against nothing but its own qualification', function () {
    $reap = PostgresBookingWriter::reapBookingsSql();

    expect(substr_count($reap, "status in ('pending', 'awaiting_approval', 'awaiting_payment')"))->toBe(2)
        ->and(substr_count($reap, 'hold_expires_at <= now()'))->toBe(2)
        ->and(substr_count($reap, "on_approval_timeout = 'auto_approve'"))->toBe(2)
        ->and($reap)->toContain('update bookings')
        ->and($reap)->not->toContain('where id in (select id from doomed)');
});

it('locks the rows it chose, in id order, so a transition committing on one of them is waited for rather than read past', function () {
    expect(PostgresBookingWriter::reapBookingsSql())->toContain('order by id for update)');
});

it('ends the hold as well as the status, because every transition out of a held state nulls the class and the expiry, and still hands both back from the row the CTE is holding', function () {
    $reap = PostgresBookingWriter::reapBookingsSql();

    // The update nulls them and the RETURNING reads them from doomed, which is the row as it
    // stood before this statement touched it, so the trail and the acknowledgement rule still
    // learn which hold was let go.
    expect($reap)->toContain('hold_class = null, hold_expires_at = null')
        ->and($reap)->toContain('returning bookings.id, doomed.uuid, doomed.status, doomed.hold_class)');
});

it('hands back what the update changed, each booking as it stood before it, so the trail and the acknowledgement rule read the status it left and never name a booking that was not expired', function () {
    $reap = PostgresBookingWriter::reapBookingsSql();

    expect($reap)->toContain('returning bookings.id, doomed.uuid, doomed.status, doomed.hold_class)')
        ->and($reap)->toContain('select id, uuid, status, hold_class from expired order by id')
        ->and($reap)->not->toContain('from doomed order by id')
        ->and($reap)->not->toContain('returning id)');
});

it('writes one audit row by resolving the booking from its public identifier, leaving created_at to the transaction clock the booking row also reads', function () {
    expect(AuditRow::sql())->toBe(
        'insert into audit_log (booking_id, actor, action, payload)'
        .' select id, ?, ?, ?::jsonb from bookings where uuid = ?::uuid',
    );
});

it('scopes the reap by staff and range and never by a day column, and skips nothing', function () {
    $reap = PostgresBookingWriter::reapBookingsSql();

    expect($reap)->not->toContain('day')
        ->and($reap)->not->toContain('skip locked')
        ->and(BlockingItems::unblockSql())->not->toContain('skip locked');
});

it('re-validates one item as a plain existence query over blocking items of the staff member, with no FOR UPDATE', function () {
    $overlap = PostgresBookingWriter::overlapSql();

    expect($overlap)->toBe(
        'select exists (select 1 from booking_items'
        ." where staff_id = ? and blocks and block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)')) as overlaps",
    )->and(strtolower($overlap))->not->toContain('for update');
});

it('inserts the booking with a server-made uuid and an expiry the TTL after the server clock, the timeout policy copied, the source, the channel and the language named, returning both with the clock reading, every item blocking with its processing end, and the note as its own row', function () {
    expect(PostgresBookingWriter::insertBookingSql())->toBe(
        'insert into bookings (uuid, status, hold_class, hold_expires_at, customer_name, customer_email, customer_phone,'
        .' total_minor, currency, payment_mode, requires_approval, on_approval_timeout, manage_token_hash, source, channel, language)'
        .' values (gen_random_uuid(), ?, ?, now() + make_interval(mins => ?), ?, ?, ?, ?, ?, ?, ?::boolean, ?, ?, ?, ?, ?)'
        .' returning id, uuid::text, hold_expires_at::text, created_at::text',
    )->and(PostgresBookingWriter::insertMetaSql())->toBe(
        'insert into booking_meta (booking_id, key, value) values (?, ?, ?)',
    )->and(PostgresBookingWriter::insertItemSql())->toBe(
        'insert into booking_items (booking_id, sort, service_id, staff_id, start_utc, end_utc, block_start_utc, block_end_utc, processing_ends_utc, price_minor, blocks)'
        .' values (?, ?, ?, ?, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?::timestamptz, ?, true)',
    );
});

it('reads the granularity for the availability step on the transaction, the table named bare since the search path is set', function () {
    expect(LockedAvailability::granularitySql())->toBe('select granularity_min from settings where id = 1');
});

it('spells an instant for the server in UTC with its offset written out, whatever zone it came in', function () {
    $athens = new DateTimeImmutable('2026-10-01 12:00:00.250', new DateTimeZone('Europe/Athens'));

    expect(PostgresBookingWriter::instant($athens))->toBe('2026-10-01 09:00:00.250000+00:00');
});

it('locks the booking row on its own statement, by the public identifier and for update, because the guarded transition takes it after the mutex rows and holds it for the rest of the transaction', function () {
    expect(PostgresBookingWriter::lockBookingSql())->toBe('select id from bookings where uuid = ?::uuid for update')
        ->and(PostgresBookingWriter::lockBookingSql())->toContain('for update');
});

it('clears a moved booking\'s session by payment_ref alone on the locked row, writing nothing of its hold, its expiry or its seal', function () {
    expect(PostgresBookingWriter::clearSessionSql())->toBe('update bookings set payment_ref = null, updated_at = now() where id = ?')
        ->and(PostgresBookingWriter::clearSessionSql())->not->toContain('hold_')->not->toContain('status')->not->toContain('manage_token_sealed');
});

it('re-reads the locked booking with its items and their services in one statement, and asks the server itself whether the hold is still live rather than handing an instant out to be compared elsewhere', function () {
    $reread = PostgresBookingWriter::readLockedSql();

    expect($reread)->toBe(
        'select b.uuid::text as uuid, b.status, b.hold_class, b.payment_mode, b.manage_token_sealed, b.payment_ref,'
        .' (b.hold_expires_at is not null and b.hold_expires_at > now()) as hold_is_live,'
        .' i.staff_id, i.start_utc::text as start_utc,'
        .' i.block_start_utc::text as block_start_utc, i.block_end_utc::text as block_end_utc,'
        .' coalesce(s.cancel_window_hours, 0) as cancel_window_hours,'
        .' coalesce(s.reschedule_window_hours, 0) as reschedule_window_hours'
        .' from bookings b join booking_items i on i.booking_id = b.id'
        .' left join services s on s.id = i.service_id'
        .' where b.id = ? order by i.sort',
    )
        ->and($reread)->toContain('hold_expires_at > now()')
        ->and($reread)->not->toContain('for update')
        ->and($reread)->toContain('block_start_utc')
        // The policy's two inputs come from the catalog as it stands now, under the same lock.
        // An item whose service has gone keeps its row and carries no window, which is the
        // outer join and the coalesce.
        ->and($reread)->toContain('left join services')
        // The predicate's third limb is spelled in SQL where the predicate belongs, in the
        // availability read and in the reap's exclusion, and no guard reads it: a column on the
        // re-read that nothing asks about is one the re-read should not be paying for.
        ->and($reread)->not->toContain('on_approval_timeout');
});

it('compares and sets in one update naming the status the re-read found, with the columns the transition writes, the sealed manage token every transition clears, and no column it did not', function () {
    expect(PostgresBookingWriter::compareAndSetSql([]))
        ->toBe('update bookings set status = ?, updated_at = now(), manage_token_sealed = null where id = ? and status = ?')
        ->and(PostgresBookingWriter::compareAndSetSql([TransitionColumn::HoldClass, TransitionColumn::HoldExpiresAt]))
        ->toBe('update bookings set status = ?, updated_at = now(), manage_token_sealed = null, hold_class = ?, hold_expires_at = ?::timestamptz where id = ? and status = ?');
});

it('spells an expiry written from the server\'s clock as now() plus a bound number of hours in the statement itself, so the payment hold an approval leaves is the database\'s instant and never the application\'s', function () {
    $written = WrittenColumns::of([
        TransitionColumn::HoldClass->value => 'payment',
        TransitionColumn::HoldExpiresAt->value => new ServerNowPlus(24),
    ]);

    expect(PostgresBookingWriter::compareAndSetSql($written->columns, $written->fromServerNow))
        ->toBe('update bookings set status = ?, updated_at = now(), manage_token_sealed = null, hold_class = ?, hold_expires_at = now() + make_interval(hours => ?) where id = ? and status = ?')
        ->and($written->bindings)->toBe(['payment', 24]);
});

it('casts every instant a transition writes and binds the rest as they are, so an approval stamp crosses as text with its offset and a user id and a reason cross as themselves', function () {
    expect(PostgresBookingWriter::compareAndSetSql([TransitionColumn::ApprovedAt, TransitionColumn::ApprovedBy]))
        ->toBe('update bookings set status = ?, updated_at = now(), manage_token_sealed = null, approved_at = ?::timestamptz, approved_by = ? where id = ? and status = ?')
        ->and(PostgresBookingWriter::compareAndSetSql([TransitionColumn::RejectionReason]))
        ->toBe('update bookings set status = ?, updated_at = now(), manage_token_sealed = null, rejection_reason = ? where id = ? and status = ?');
});
