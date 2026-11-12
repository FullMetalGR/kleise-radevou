<?php

use App\Tenancy\ExclusiveStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The languages a tenant schema keeps.
 *
 * settings.language is the language the tenant chose for itself in Settings, null on every row
 * until it is chosen, the tenant default reading tenants.locale where it is null.
 * bookings.language is the language the booking was made in, written once by the hold from the
 * hold request's language and by the admin's manual booking as the tenant default, and never
 * updated; every booking on file before it reads en, the language it was made in, through the
 * column's default. Each is held to en or el by its CHECK, settings_language_check and
 * bookings_language_check.
 *
 * Safe on a schema holding live bookings. Adding a column with a constant default rewrites no
 * table, and each CHECK scans its table once to validate the rows it holds, every one of which
 * reads null or en and passes. Each ALTER takes ACCESS EXCLUSIVE on its table alone, held to the
 * schema's commit, so a booking write waits out the scan of bookings, one waiting past the
 * transaction runner's lock timeout answering busy; before the lock is granted each waits behind
 * every open transaction that has read its table, so both run under ExclusiveStep, and give up
 * after that lock timeout, the schema rolled back and named by tenant:migrate to be run again.
 * Nothing of booking_items is touched, so no exclusion constraint and no GiST index is rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared(<<<'SQL'
                alter table settings
                    add column language text null constraint settings_language_check check (language in ('en', 'el'))
                SQL);
            DB::unprepared(<<<'SQL'
                alter table bookings
                    add column language text not null default 'en' constraint bookings_language_check check (language in ('en', 'el'))
                SQL);
        });
    }

    public function down(): void
    {
        ExclusiveStep::run(DB::connection(), function (): void {
            DB::unprepared('alter table bookings drop column language');
            DB::unprepared('alter table settings drop column language');
        });
    }
};
