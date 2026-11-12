<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The customer's implicit account: no password and no sign-up, an address and a link.
 *
 * customer_links is the one-time link a My bookings request mints: the address it was asked for,
 * the sha256 of its token and never the token, its window of fifteen minutes, and used_at, which
 * the opening stamps so the link opens once. customer_sessions is what an opened link becomes: the
 * same address, the sha256 of the session's secret, thirty days, and revoked_at, which the
 * sign-out stamps. Both windows are written from the Clock port as UTC text with the offset, so
 * neither column has a default.
 *
 * The address is citext, as bookings.customer_email is, because the page lists every booking of
 * the address as citext compares it, and the one comparison must be the same at both ends. Each
 * hash is unique, which is the index the opening and every page read find a row by, and a CHECK
 * holds it to a sha256 in lower-case hex, the one spelling the writer makes, so a plain secret
 * stored by mistake is refused rather than kept. A window ends after it begins.
 *
 * Both tables are personal data, an address each, and join the erasure by address; a link past its
 * life and a session past its end are deleted by the sweep. The audit trail references neither.
 *
 * Additive: two new empty tables, and nothing reads them but the My bookings routes and the job
 * that mails the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table customer_links (
                id bigserial primary key,
                email citext not null,
                token_hash text not null,
                created_at timestamptz not null,
                expires_at timestamptz not null,
                used_at timestamptz,
                constraint customer_links_token_hash_unique unique (token_hash),
                constraint customer_links_token_hash_check check (token_hash ~ '^[0-9a-f]{64}$'),
                constraint customer_links_window_check check (expires_at > created_at)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            create table customer_sessions (
                id bigserial primary key,
                email citext not null,
                session_hash text not null,
                created_at timestamptz not null,
                expires_at timestamptz not null,
                revoked_at timestamptz,
                constraint customer_sessions_session_hash_unique unique (session_hash),
                constraint customer_sessions_session_hash_check check (session_hash ~ '^[0-9a-f]{64}$'),
                constraint customer_sessions_window_check check (expires_at > created_at)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('customer_links');
    }
};
