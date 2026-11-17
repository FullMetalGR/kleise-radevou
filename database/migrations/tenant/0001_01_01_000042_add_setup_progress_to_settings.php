<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Setup's own facts beside the two markers the steps already read: when the setup wizard first
 * came up by itself, when every step that applies was first done or skipped, when the business
 * profile was saved from the wizard, when Team and Online payments were skipped, and when Finish
 * was pressed on the Booking link step. Each is the database's own now() on the write that stamps
 * it, written once and never cleared, so a fact of the business stays true whoever acted and
 * whatever changes later.
 *
 * Additive and safe on a schema holding rows: six nullable columns with no default are a catalog
 * change, and every existing tenant reads each as unset until its owner acts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                add column setup_opened_at timestamptz,
                add column setup_finished_at timestamptz,
                add column setup_profile_saved_at timestamptz,
                add column setup_team_skipped_at timestamptz,
                add column setup_payments_skipped_at timestamptz,
                add column setup_link_finished_at timestamptz
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table settings
                drop column setup_link_finished_at,
                drop column setup_payments_skipped_at,
                drop column setup_team_skipped_at,
                drop column setup_profile_saved_at,
                drop column setup_finished_at,
                drop column setup_opened_at
            SQL);
    }
};
