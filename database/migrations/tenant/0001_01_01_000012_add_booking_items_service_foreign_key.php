<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Adds the foreign key from booking_items.service_id to services, a column migration 000004
 * created as a plain bigint before the services table existed. It is the delete guard's
 * serialisation: an item insert takes a key share lock on the services row it names for the life
 * of the hold's transaction, and a delete of that row takes the lock a key share conflicts with,
 * so a delete standing behind an uncommitted item waits and is refused once the item commits,
 * and a delete that lands first refuses the item. A check in the application before the delete
 * would be two statements with nothing between them, and a hold committing in the gap would
 * leave an item naming nothing.
 *
 * The key is safe where the one on staff_days is not, and for a reason service_staff and
 * availability_rules did not need: the booking path writes here, so the lock is real, but a key
 * share conflicts only with the lock a delete takes, or an update of a column under a unique
 * index, and services has no unique index but its primary key, which nothing rewrites. So an
 * ordinary service save neither waits behind a hold nor makes one wait, and two holds on one
 * service share the row; the delete alone waits, and only for the hold's own transaction.
 *
 * On delete restrict rather than the default, so the refusal is spelled and checked at the
 * statement, not deferred. The plain form validates the existing rows under a lock that holds
 * the booking path off booking_items for the scan; it was written for schemas holding no live
 * bookings, and the form for one that does is: the constraint added not valid, then validated in
 * a statement of its own outside the migration's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table booking_items
                add constraint booking_items_service_fkey
                    foreign key (service_id) references services (id) on delete restrict
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('alter table booking_items drop constraint booking_items_service_fkey');
    }
};
