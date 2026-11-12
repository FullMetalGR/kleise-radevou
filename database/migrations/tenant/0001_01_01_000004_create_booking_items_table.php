<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The unit that consumes capacity: one staff member for one range. Items carry no status.
 *
 * block_span is generated from its endpoints so it cannot drift from them, half-open so an item
 * ending at 10:00 and one starting at 10:00 do not overlap. blocks is maintained by the write
 * protocol and is the predicate of the exclusion constraint, which makes a double booking
 * structurally impossible for any two rows that both block.
 *
 * The exclusion constraint creates the GiST index that the availability range query and the
 * protocol's reap both use. No second index goes over the same columns. btree_gist supplies the
 * GiST equality operator class for staff_id; it is created in the public schema, beside citext,
 * because an extension created inside a tenant schema would fall with that schema and take every
 * other tenant's index with it.
 *
 * service_id is a plain bigint here: the services table is created by a later migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('create extension if not exists btree_gist schema public');

        DB::unprepared(<<<'SQL'
            create table booking_items (
                id bigserial primary key,
                booking_id bigint not null references bookings (id) on delete cascade,
                sort integer not null,
                service_id bigint not null,
                staff_id bigint not null,
                start_utc timestamptz not null,
                end_utc timestamptz not null,
                block_start_utc timestamptz not null,
                block_end_utc timestamptz not null,
                block_span tstzrange generated always as (tstzrange(block_start_utc, block_end_utc, '[)')) stored,
                processing_ends_utc timestamptz,
                price_minor bigint not null,
                blocks boolean not null default true,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint booking_items_no_overlap
                    exclude using gist (staff_id with =, block_span with &&) where (blocks),
                constraint booking_items_processing_after_end
                    check (processing_ends_utc is null or processing_ends_utc > end_utc)
            )
            SQL);

        DB::unprepared('create index booking_items_booking on booking_items (booking_id)');
        DB::unprepared('create index booking_items_service on booking_items (service_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_items');
    }
};
