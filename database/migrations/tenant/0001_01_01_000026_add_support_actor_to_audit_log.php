<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The fifth actor shape: support:{operator id}, the platform's operator acting on this tenant's
 * bookings in a support session, named by the id of the central operators row and never by name
 * or address, as a user is named by id. The trail's no-personal-data rule is unchanged: an
 * operator id is a reference, as a user id is.
 *
 * The CHECK is dropped and added again in one statement with the fifth alternative, so the table
 * is never without it. The patterns are spelled here as POSIX regexes rather than imported from
 * App\Domain\Audit\Actor, for the reason migration 000010 gives: a shipped migration must not
 * change when a constant does, and a test holds the constraint and the class to each other by
 * what each accepts rather than by comparing two texts.
 *
 * Safe on a schema holding live bookings. Adding the CHECK scans audit_log once to validate the
 * rows it holds, every one of which is in one of the four shapes the old CHECK allowed and so
 * passes the new one. The ALTER takes ACCESS EXCLUSIVE on audit_log alone, held through the scan
 * to the schema's commit, so a booking's trail write and the dialog's trail read wait out the
 * scan and the rest of the schema's migrations, a write waiting past the transaction runner's
 * lock timeout answering busy. Before the lock is granted the ALTER waits behind every open
 * transaction that has read audit_log, and every later reader and writer of the table queues
 * behind it; so it runs under ExclusiveStep, the house rule for a tenant migration taking ACCESS
 * EXCLUSIVE, and gives up after that lock timeout, the schema rolled back and named by
 * tenant:migrate to be run again. Nothing of bookings or booking_items is touched, so no
 * exclusion constraint and no GiST index is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), fn () => DB::unprepared(<<<'SQL'
            alter table audit_log
                drop constraint audit_log_actor_check,
                add constraint audit_log_actor_check check (
                    actor in ('customer', 'system')
                    or actor ~ '^user:[0-9]+$'
                    or actor ~ '^job:[a-z][a-z0-9_-]*$'
                    or actor ~ '^support:[0-9]+$'
                )
            SQL));
    }

    /**
     * Back to the four shapes. It fails where a support row has been written since, which is
     * the answer wanted: a rollback that dropped support rows would rewrite the trail.
     */
    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), fn () => DB::unprepared(<<<'SQL'
            alter table audit_log
                drop constraint audit_log_actor_check,
                add constraint audit_log_actor_check check (
                    actor in ('customer', 'system')
                    or actor ~ '^user:[0-9]+$'
                    or actor ~ '^job:[a-z][a-z0-9_-]*$'
                )
            SQL));
    }
};
