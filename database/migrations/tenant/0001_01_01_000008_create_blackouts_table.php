<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A dated override that removes availability: tenant-wide with no staff member, personal with
 * one; the whole day with no window, that window with one. The reason is a note for the owner
 * and never reaches a customer.
 *
 * The one CHECK holds the window to both ends null or both set and ordered, which is what the
 * domain refuses too. The index on the date serves the tenant-wide read over a window of days
 * and the one on the pair serves the personal read. A personal blackout follows its staff
 * member out of the table; a tenant-wide one names nobody and stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table blackouts (
                id bigserial primary key,
                staff_id bigint references staff (id) on delete cascade,
                date date not null,
                start_time time,
                end_time time,
                reason text,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint blackouts_window_check check (
                    (start_time is null and end_time is null)
                    or (start_time is not null and end_time is not null and start_time < end_time)
                )
            )
            SQL);

        DB::unprepared('create index blackouts_date on blackouts (date)');
        DB::unprepared('create index blackouts_staff_date on blackouts (staff_id, date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('blackouts');
    }
};
