<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A booking's intake fields: one row per key a booking carries, the pair unique as the primary
 * key, the value text. The one key v1 writes is note, the customer's "Anything we should know?",
 * at most 500 characters, which the CHECK holds to the length the hold refuses past and to a
 * non-empty value, since the hold stores a blank note as no row.
 *
 * Anything a tenant collects here is personal data by default, so the rows go with their
 * booking, on delete cascade, and the erasure deletes them outright. The audit trail references
 * the booking and never copies a row of this table.
 *
 * Additive: a new empty table, and nothing reads it but the dialog and the business's two
 * messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table booking_meta (
                booking_id bigint not null references bookings (id) on delete cascade,
                key text not null,
                value text not null,
                constraint booking_meta_pkey primary key (booking_id, key),
                constraint booking_meta_note_check
                    check (key <> 'note' or char_length(value) between 1 and 500)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_meta');
    }
};
