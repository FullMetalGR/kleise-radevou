<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * An index on audit_log (created_at): the admin's recent activity reads the latest rows of the
 * whole trail by created_at, and the one index the table had leads with booking_id, so that read
 * scanned the trail and grew with it.
 *
 * Safe on a schema holding live bookings. CREATE INDEX takes SHARE on audit_log alone, held to the
 * schema's commit: readers go on, but a booking's trail write waits behind it, and before the lock
 * is granted it waits behind every open transaction that has written the trail. So it runs under
 * ExclusiveStep's lock timeout and gives up rather than queue the schema's transitions behind a
 * long writer, the schema rolled back and named by tenant:migrate to be run again. The build reads
 * the trail once; nothing of bookings or booking_items is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('create index audit_log_created_at on audit_log (created_at)');
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('drop index audit_log_created_at');
        });
    }
};
