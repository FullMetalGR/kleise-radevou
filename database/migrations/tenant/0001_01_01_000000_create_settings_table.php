<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A single row per tenant, enforced by the CHECK on id. The column defaults are the settings
 * defaults, so provisioning inserts the row and names nothing.
 *
 * emails_off is stored as the negative: absence means "sending", so a message key added in a
 * later release sends by default rather than silently not. brand_accent null means the product
 * default. The defaults for the two format columns are day-month-year with a 24-hour clock, as
 * PHP date format tokens.
 *
 * Written as DDL rather than through the schema builder because the builder has no text[] and no
 * CHECK, the same choice the central migrations made.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table settings (
                id bigserial primary key,
                checkout_ttl_min integer not null default 15,
                approval_ttl_hours integer not null default 48,
                payment_ttl_hours integer not null default 24,
                reminder_lead_hours integer not null default 24,
                granularity_min integer not null default 5,
                start_of_week integer not null default 1,
                date_format text not null default 'd/m/Y',
                time_format text not null default 'H:i',
                emails_off text[] not null default '{}',
                brand_accent text,
                brand_logo_path text,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint settings_single_row check (id = 1)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
