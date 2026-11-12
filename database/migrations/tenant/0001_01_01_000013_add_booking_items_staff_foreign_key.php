<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The staff delete guard's serialisation, in the shape 000012 gave the catalog's: an item
 * insert takes a key share lock on the staff row it names for the life of the hold's
 * transaction, and a delete of that row takes the lock a key share conflicts with, so a
 * delete standing behind an uncommitted item waits and is refused once the item commits, and
 * a delete that lands first refuses the item. A member any booking item names, in any status,
 * is never deleted, whichever order the requests interleave in.
 *
 * An ordinary save of the member neither waits behind a hold nor makes one wait: an update
 * takes FOR NO KEY UPDATE unless it rewrites a column under a unique index a key could use,
 * and the one unique index on staff, the partial one over a constant, is not such an index.
 * staff_days keeps withholding its key on purpose; this key is on the items alone.
 *
 * On delete restrict, checked at the statement, as 000012. The plain form validates the
 * existing rows under a lock that holds the booking path off booking_items for the scan; it
 * was written for schemas holding no live bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table booking_items
                add constraint booking_items_staff_fkey
                    foreign key (staff_id) references staff (id) on delete restrict
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('alter table booking_items drop constraint booking_items_staff_fkey');
    }
};
