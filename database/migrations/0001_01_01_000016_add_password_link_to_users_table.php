<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The set-password link: the console mails a user a link of 32 random bytes as hex, and the users
 * row keeps its sha256 and the instant it lapses, 72 hours on. The clear token lives in the link
 * alone. One hash names one user, so the column is unique, nulls apart. Both are null outside a
 * link, written together by the mint and cleared together by the set that uses it, and the CHECK
 * holds them to that, so a row cannot carry a hash that never lapses or an expiry naming no link.
 *
 * A credential's hash and an instant, not personal data: the set clears them, and a lapsed link's
 * hash is dead where it stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table users
                add column password_link_hash text,
                add column password_link_expires_at timestamptz,
                add constraint users_password_link_hash_unique unique (password_link_hash),
                add constraint users_password_link_check check (
                    (password_link_hash is null) = (password_link_expires_at is null)
                )
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table users
                drop constraint users_password_link_check,
                drop constraint users_password_link_hash_unique,
                drop column password_link_expires_at,
                drop column password_link_hash
            SQL);
    }
};
