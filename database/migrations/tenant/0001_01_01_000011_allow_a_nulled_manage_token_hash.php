<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Retention keeps manage_token_hash until thirty days after a booking's last segment ends and
 * then nulls it, which is what ends the magic link while the booking itself is kept for
 * accounting for two years. The column was created not null, so that step could never run: the
 * only ways to end the link would have been to overwrite the hash with a value no token hashes
 * to, which stores a lie, or to delete the booking, which loses the accounting rows. Dropping
 * the constraint is what makes that retention possible.
 *
 * Safe on a schema holding live bookings: dropping a not null is a catalog change with no table
 * rewrite and no index rebuild, and it touches neither booking_items nor its exclusion
 * constraint. Every existing row keeps its hash, and the guard reads a nulled one as a refusal,
 * the same refusal as a wrong token, so the link stops working the day retention runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('alter table bookings alter column manage_token_hash drop not null');
    }

    /**
     * Going back needs every row to hold a hash. A schema whose retention has already nulled one
     * cannot take the constraint again, and the migration says so rather than inventing a value
     * that would read as a live credential.
     */
    public function down(): void
    {
        DB::unprepared('alter table bookings alter column manage_token_hash set not null');
    }
};
