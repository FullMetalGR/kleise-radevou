<?php

use App\Infrastructure\Db\MasksFromRows;
use App\Infrastructure\Db\SqlText;

/*
 * The four statements the availability read runs, spelled out, and the one blocking predicate
 * inside the last. The feature suite runs them; this file holds the text to what each statement
 * must do.
 */

it('spells the blocking predicate once, in three clauses: confirmed, held with a live expiry, or an approval request that auto-approves on timeout', function () {
    expect(MasksFromRows::blockingPredicate())->toBe(
        "b.status = 'confirmed'"
        ." or (b.status in ('pending', 'awaiting_approval', 'awaiting_payment') and b.hold_expires_at > now())"
        ." or (b.status = 'awaiting_approval' and b.on_approval_timeout = 'auto_approve')",
    );
});

it('reads status and expiry and never the blocks flag', function () {
    $items = MasksFromRows::itemsSql('');

    expect(preg_match('/\bblocks\b/', $items))->toBe(0)
        ->and(preg_match('/\bblocks\b/', MasksFromRows::blockingPredicate()))->toBe(0)
        ->and($items)->toContain('status')
        ->toContain('hold_expires_at');
});

it('fetches the shift patterns of the staff members asked for in one statement, beside the settings row\'s week start, which comes back on its own row where none of them has a pattern, prefixed for the caller', function () {
    expect(MasksFromRows::patternsSql('"tenant_acme".'))->toBe(
        'select s.start_of_week, p.staff_id, p.starts_on::text as starts_on, p.cadence_weeks,'
        .' p.ends_on::text as ends_on, p.windows::text as windows'
        .' from "tenant_acme".settings s'
        .' left join "tenant_acme".shift_patterns p on p.staff_id = any(?::bigint[])'
        .' where s.id = 1'
        .' order by p.staff_id',
    )->and(MasksFromRows::patternsSql(''))->toContain(' from settings s left join shift_patterns p ');
});

it('fetches the overrides of the members asked for on the window\'s dates in one statement, prefixed for the caller', function () {
    expect(MasksFromRows::overridesSql('"tenant_acme".'))->toBe(
        'select staff_id, date::text as date, windows::text as windows'
        .' from "tenant_acme".shift_overrides'
        .' where date between ?::date and ?::date'
        .' and staff_id = any(?::bigint[])'
        .' order by staff_id, date',
    )->and(MasksFromRows::overridesSql(''))->toContain(' from shift_overrides ');
});

it('fetches the blackouts of the window in one statement, tenant-wide and personal to the members asked for', function () {
    expect(MasksFromRows::blackoutsSql('"tenant_acme".'))->toBe(
        'select staff_id, date, start_time, end_time'
        .' from "tenant_acme".blackouts'
        .' where date between ?::date and ?::date'
        .' and (staff_id is null or staff_id = any(?::bigint[]))'
        .' order by date, id',
    );
});

it('fetches the blocking items of the members asked for as a range over block_span, joined to their booking, the predicate applied, every instant handed back as UTC text with its offset, and beside each item the expiry that alone will free it', function () {
    expect(MasksFromRows::itemsSql('"tenant_acme".'))->toBe(
        'select i.staff_id,'
        .' to_char(i.block_start_utc at time zone \'UTC\', \'YYYY-MM-DD HH24:MI:SS.US"+00:00"\') as block_start,'
        .' to_char(i.block_end_utc at time zone \'UTC\', \'YYYY-MM-DD HH24:MI:SS.US"+00:00"\') as block_end,'
        ." case when b.status = 'confirmed' then null"
        .' else to_char(b.hold_expires_at at time zone \'UTC\', \'YYYY-MM-DD HH24:MI:SS.US"+00:00"\') end as hold_expiry'
        .' from "tenant_acme".booking_items i'
        .' join "tenant_acme".bookings b on b.id = i.booking_id'
        .' where i.staff_id = any(?::bigint[])'
        ." and i.block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)')"
        .' and ('.MasksFromRows::blockingPredicate().')'
        .' order by i.staff_id, i.block_start_utc, i.id',
    )->and(strtolower(MasksFromRows::itemsSql('')))->not->toContain('for update');
});

it('leaves one booking out of the items statement by its identifier and changes nothing else, so a statement the write protocol runs is never this one', function () {
    $plain = MasksFromRows::itemsSql('"tenant_acme".');
    $leaving = MasksFromRows::itemsSql('"tenant_acme".', leavingOut: true);

    expect($leaving)->toBe(str_replace(' order by i.staff_id,', ' and b.uuid <> ?::uuid order by i.staff_id,', $plain))
        ->and($leaving)->not->toBe($plain)
        ->and(MasksFromRows::itemsSql(''))->not->toContain('uuid');
});

it('spells an instant for the server in UTC with its offset written out, and a list of ids as a bigint array literal', function () {
    $athens = new DateTimeImmutable('2026-10-01 12:00:00.250', new DateTimeZone('Europe/Athens'));

    expect(SqlText::instant($athens))->toBe('2026-10-01 09:00:00.250000+00:00')
        ->and(SqlText::bigintArray([3, 1, 2]))->toBe('{3,1,2}')
        ->and(SqlText::bigintArray([]))->toBe('{}');
});

it('counts the loads of the members asked for on one day with the same join and the same predicate as the items statement, one row per member with any', function () {
    expect(MasksFromRows::loadsSql('"tenant_acme".'))->toBe(
        'select i.staff_id, count(*)::int as load'
        .' from "tenant_acme".booking_items i'
        .' join "tenant_acme".bookings b on b.id = i.booking_id'
        .' where i.staff_id = any(?::bigint[])'
        ." and i.block_span && tstzrange(?::timestamptz, ?::timestamptz, '[)')"
        .' and ('.MasksFromRows::blockingPredicate().')'
        .' group by i.staff_id'
        .' order by i.staff_id',
    )->and(preg_match('/\\bblocks\\b/', MasksFromRows::loadsSql('')))->toBe(0);
});
