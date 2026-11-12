<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A member's own calendar colour: staff.colour, one word of the Calendar's palette, null for
 * Automatic, the spread over the ids every member is on until someone picks. Held to the seven
 * words of App\Tenancy\StaffColour by its CHECK, staff_colour_check, which restates
 * StaffColour::words() in SQL, since a statement here is a literal string by the static
 * analysis's rule; TenantSchemaTest holds the two to the same words in the same order. Written
 * from the Staff screen's Calendar colours card by PUT /admin/staff/{id}/colour.
 *
 * Safe on a schema holding live bookings. Adding a nullable column with no default rewrites no
 * table and the CHECK scans the staff rows, a handful. The ALTER takes ACCESS EXCLUSIVE on staff
 * alone, held to the schema's commit; before the lock is granted it waits behind every open
 * transaction that has read staff, the booking path among them, so it runs under ExclusiveStep,
 * and gives up after the transaction runner's lock timeout, the schema rolled back and named by
 * tenant:migrate to be run again. Nothing of bookings or booking_items is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                alter table staff
                    add column colour text constraint staff_colour_check check (colour in ('orange', 'sky_blue', 'green', 'yellow', 'blue', 'vermilion', 'purple'))
                SQL);
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('alter table staff drop column colour');
        });
    }
};
