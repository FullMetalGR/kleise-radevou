<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The mutex table. Every booking write locks rows here and nowhere else. The composite key is the
 * lock key and the conflict target of the single statement in the write protocol, so there is no
 * surrogate id. rev is bumped inside the write transaction and is the cache key for that
 * staff-day's free/busy masks.
 *
 * staff_id carries no foreign key, as specified: a foreign key check takes a share lock on the
 * referenced staff row, and this table exists to be locked on its own terms.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table staff_days (
                staff_id bigint not null,
                day date not null,
                rev bigint not null default 0,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint staff_days_pkey primary key (staff_id, day)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_days');
    }
};
