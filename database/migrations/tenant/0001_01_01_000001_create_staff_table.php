<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A person who holds hours and takes bookings. user_id names a central users row by id only,
 * never by foreign key across schemas, and null is the normal case: most staff never sign in.
 *
 * Exactly one row may carry is_implicit, the row that stands for the business itself. The partial
 * unique index over a constant enforces it. The flag exists so a notification template can tell
 * the business row from a real second person without an entitlement lookup at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table staff (
                id bigserial primary key,
                user_id bigint,
                name text not null,
                email citext,
                status text not null,
                is_implicit boolean not null default false,
                sort integer not null default 0,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint staff_status_check check (status in ('active', 'inactive'))
            )
            SQL);

        DB::unprepared('create unique index staff_one_implicit on staff ((true)) where is_implicit');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
