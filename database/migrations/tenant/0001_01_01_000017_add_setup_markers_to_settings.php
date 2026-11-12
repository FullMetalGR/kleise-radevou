<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The two setup markers: when the opening hours were last confirmed, written by PUT
 * /admin/opening-hours and by a staff save that carries rules, and when the booking link was
 * last copied, written by POST /admin/settings/link-copied. The checklist on the Calendar,
 * asks whether each is set, never when; each is the database's own now() on the write that
 * marks it and nothing ever clears it.
 *
 * Additive and safe on a schema holding rows: two nullable columns with no default are a
 * catalog change, and every existing tenant reads both as unset until its owner acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                add column hours_confirmed_at timestamptz,
                add column link_copied_at timestamptz
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                drop column link_copied_at,
                drop column hours_confirmed_at
            SQL);
    }
};
