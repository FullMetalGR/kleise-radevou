<?php

use App\Infrastructure\Db\RevisionsFromRows;

/*
 * The one statement the cache reader runs before it looks anything up: the revisions of the
 * mutex rows that exist among the staff-days of a read, and the server's now beside them, so
 * the reader compares an entry's earliest hold expiry against the clock the blocking predicate
 * reads and not against its own.
 */

it('fetches the revisions of the mutex rows that exist among the staff-days asked for in one statement that always answers the server\'s now, prefixed for the caller', function () {
    expect(RevisionsFromRows::sql('"tenant_acme".'))->toBe(
        'select s.staff_id, s.day, s.rev,'
        .' to_char(now() at time zone \'UTC\', \'YYYY-MM-DD HH24:MI:SS.US"+00:00"\') as server_now'
        .' from (select 1) as t'
        .' left join "tenant_acme".staff_days s on s.staff_id = any(?::bigint[]) and s.day between ?::date and ?::date'
        .' order by s.staff_id, s.day',
    )->and(RevisionsFromRows::sql(''))->toContain(' left join staff_days s on ');
});
