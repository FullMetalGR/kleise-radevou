<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The platform's operators: the people who sign in to the console. An operator belongs to the
 * platform and to no tenant, so there is no tenant_id, and the one role is every power, so there is
 * no role; the console's sign-in remembers nobody, so there is no remember token. Email is unique
 * across the table, compared as citext. Never a row in users, which belongs to tenants. Nobody
 * registers: the console command adds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table operators (
                id bigserial primary key,
                name text not null,
                email citext not null,
                password text not null,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint operators_email_unique unique (email)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
