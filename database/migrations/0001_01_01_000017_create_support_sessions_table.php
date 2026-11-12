<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The support session: the operator presses Open as support on a tenant's detail and the console
 * writes a row naming the operator and the tenant, the sha256 of a one-time link of 32 random
 * bytes as hex, and the instant the session lapses, sixty minutes from the press. The tenant
 * host's link opens the row once: it writes opened_at and clears the hash in one statement, so the
 * hash names a row only while the link is unused, and the CHECK holds a row to that, a hash and an
 * opening never standing together. ended_at is the end an operator writes; expiry is read off
 * expires_at and writes nothing.
 *
 * One hash names one row, so the column is unique, nulls apart. The operator and the tenant are
 * references with no cascade, as the platform audit log's are, so a row keeps naming who acted and
 * where. References and instants alone, no name and no address: the row is kept 90 days after its
 * end, the retention sweep's.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table support_sessions (
                id bigserial primary key,
                operator_id bigint not null references operators (id),
                tenant_id bigint not null references tenants (id),
                token_hash text,
                opened_at timestamptz,
                expires_at timestamptz not null,
                ended_at timestamptz,
                created_at timestamptz not null default now(),
                constraint support_sessions_token_hash_unique unique (token_hash),
                constraint support_sessions_token_check check (token_hash is null or opened_at is null)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_sessions');
    }
};
