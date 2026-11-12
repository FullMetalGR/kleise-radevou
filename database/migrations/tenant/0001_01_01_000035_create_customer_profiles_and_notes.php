<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A customer's profile and notes. A customer is still no row of its own but the bookings of one
 * address; what the business keeps about that address beside the bookings hangs on the address, as
 * the customer's page does.
 *
 * customer_profiles is one row per address: the address in lower case as its key, citext as
 * bookings.customer_email is, so the page, the list and the hold match it as the bookings'
 * column compares it, and a CHECK holds it to the one spelling its writer makes. tags is a text[]
 * of at most ten words, each trimmed, one to twenty-four characters and holding no comma, which is
 * what the Profile card's field splits on; the CHECK reads the array as text joined by commas,
 * so a null element, an empty one, one with a space at either end, one holding a comma (the
 * joined text then splits into more words than the array holds), the eleventh and the
 * twenty-fifth character are each refused, and the writer keeps each word once.
 * birthday is a date, null where none is recorded; language one of the product's two, en or el,
 * as settings.language and bookings.language are, null for not recorded. blocked_at stamps the
 * block on online booking and blocked_reason says why, for the staff; a reason never stands
 * without its block. The two stamps are written from the Clock port as UTC text with the offset,
 * so neither has a default.
 *
 * customer_notes is the staff's notes on an address: the address in lower case under the same
 * CHECK, a body that is not blank and at most 2000 characters, its author as the trail names an
 * actor (user:{id}, or support:{operator id} for the platform's support in a session) beside the
 * name they wrote it under, kept as written so a note still names a user the tenant no longer has,
 * and created_at, the Clock port's. An index on the address answers the page's list.
 *
 * Both tables are personal data, an address each, and a note may hold health data. They join
 * erasure by address (CustomerErasure deletes both), the customer's export (CustomerExport carries
 * both), and the retention sweep, which deletes a profile and the notes of an address no booking
 * carries any more, so they go with the last booking that kept the person. The audit trail
 * references neither.
 *
 * Additive: two new empty tables with no foreign key into any existing table, so nothing is
 * locked beyond the schema's own catalog and no booking path waits on this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table customer_profiles (
                email citext primary key,
                tags text[] not null default '{}',
                birthday date,
                language text,
                blocked_at timestamptz,
                blocked_reason text,
                created_at timestamptz not null,
                updated_at timestamptz not null,
                constraint customer_profiles_email_check check (email::text = lower(email::text)),
                constraint customer_profiles_tags_check check (
                    cardinality(tags) <= 10
                    and array_position(tags, null) is null
                    and cardinality(string_to_array(array_to_string(tags, ','), ',')) = cardinality(tags)
                    and (cardinality(tags) = 0 or array_to_string(tags, ',') ~ '^[^,[:space:]](?:[^,]{0,22}[^,[:space:]])?(?:,[^,[:space:]](?:[^,]{0,22}[^,[:space:]])?)*$')
                ),
                constraint customer_profiles_language_check check (language in ('en', 'el')),
                constraint customer_profiles_block_check check (blocked_at is not null or blocked_reason is null)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            create table customer_notes (
                id bigserial primary key,
                email citext not null,
                body text not null,
                author_actor text not null,
                author_name text not null,
                created_at timestamptz not null,
                constraint customer_notes_email_check check (email::text = lower(email::text)),
                constraint customer_notes_body_check check (btrim(body) <> '' and char_length(body) <= 2000),
                constraint customer_notes_author_check check (author_actor ~ '^user:[0-9]+$' or author_actor ~ '^support:[0-9]+$')
            );
            create index customer_notes_email_index on customer_notes (email, created_at)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_profiles');
    }
};
