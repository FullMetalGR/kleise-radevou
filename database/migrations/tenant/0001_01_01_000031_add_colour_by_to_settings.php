<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * How every calendar grid of the tenant colours its blocks: settings.colour_by, one word, staff
 * by default, held to staff, category or status by its CHECK, settings_colour_by_check. Written
 * from the Calendar's filter card by PUT /admin/settings/colour-by and read by every user of
 * the tenant; not per user.
 *
 * Safe on a schema holding live bookings. Adding a column with a constant default rewrites no
 * table, and the CHECK scans the one settings row. The ALTER takes ACCESS EXCLUSIVE on settings
 * alone, held to the schema's commit; before the lock is granted it waits behind every open
 * transaction that has read settings, the booking path among them, so it runs under
 * ExclusiveStep, and gives up after the transaction runner's lock timeout, the schema rolled
 * back and named by tenant:migrate to be run again. Nothing of bookings or booking_items is
 * touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                alter table settings
                    add column colour_by text not null default 'staff' constraint settings_colour_by_check check (colour_by in ('staff', 'category', 'status'))
                SQL);
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('alter table settings drop column colour_by');
        });
    }
};
