<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * An erased customer: erasure keeps the booking and loses the person, so a booking's
 * customer_name and customer_email may be null, which they were created unable to be (migration
 * 000003). Retention nulls the customer fields 24 months after the last segment ends, and the
 * admin's erasure nulls them on request; both run App\Retention\Erasure, which nulls the three
 * columns together.
 *
 * The CHECK bookings_customer_check holds the three to one of two shapes, so a half-erased row
 * cannot exist: all three null, the customer erased, or a name and an address set with the phone
 * as the customer gave it. A row of the first shape reads as "Customer erased" on every surface
 * and is mailed by nobody; a name without an address, or an address without a name, would be a
 * booking every reader had to guess about.
 *
 * Safe on a schema holding live bookings. Dropping a not null is a catalog change with no table
 * rewrite, and adding the CHECK scans bookings once to validate the rows it holds, every one of
 * which has a name and an address and so passes. The ALTER takes ACCESS EXCLUSIVE on bookings
 * alone, held to the schema's commit, so a booking write waits out the scan, one waiting past
 * the transaction runner's lock timeout answering busy; before the lock is granted the ALTER
 * waits behind every open transaction that has read bookings, so it runs under ExclusiveStep,
 * and gives up after that lock timeout, the schema rolled back and named by tenant:migrate to be
 * run again. Nothing of booking_items is touched, so no exclusion constraint and no GiST index
 * is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), fn () => DB::unprepared(<<<'SQL'
            alter table bookings
                alter column customer_name drop not null,
                alter column customer_email drop not null,
                add constraint bookings_customer_check check (
                    (customer_name is null and customer_email is null and customer_phone is null)
                    or (customer_name is not null and customer_email is not null)
                )
            SQL));
    }

    /**
     * Back to a required name and address. It fails where a booking has been erased since, which
     * is the answer wanted, as migration 000011's down() answers a nulled hash: a rollback that
     * invented a name and an address would store a person who is not there.
     */
    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), fn () => DB::unprepared(<<<'SQL'
            alter table bookings
                drop constraint bookings_customer_check,
                alter column customer_name set not null,
                alter column customer_email set not null
            SQL));
    }
};
