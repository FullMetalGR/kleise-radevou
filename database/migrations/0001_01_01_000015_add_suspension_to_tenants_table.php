<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A suspension's two facts: the instant the console's suspend wrote and the operator's reason, the
 * one the owners are mailed. Both are null outside a suspension, written together by the suspend
 * and cleared together by the resume, and the CHECK holds them to that, so a row cannot carry a
 * reason with no instant or an instant with no reason.
 *
 * The reason is free text an operator writes about a business and is personal data by possibility:
 * nothing keeps it past the resume, which nulls it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table tenants
                add column suspended_at timestamptz,
                add column suspension_reason text,
                add constraint tenants_suspension_check check (
                    (suspended_at is null) = (suspension_reason is null)
                )
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table tenants
                drop constraint tenants_suspension_check,
                drop column suspension_reason,
                drop column suspended_at
            SQL);
    }
};
