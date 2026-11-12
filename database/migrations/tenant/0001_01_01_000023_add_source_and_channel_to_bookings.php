<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Where a booking came from: bookings.source, the surface the server stamped, hosted, embed or
 * admin, not null and with no default, so every writer names one; and bookings.channel, the tag
 * a hosted-page link carried, one of the fixed five or null. The CHECKs list the cases of
 * BookingSource and BookingChannel in their order, and a test holds each list to its enum.
 *
 * Safe on a schema holding live bookings. The column is added with a constant default, a
 * catalog change on PostgreSQL that rewrites no row, and the next statement drops the default,
 * so a row written after this migration has to name its source while every row written before
 * reads embed: what the server answers for a hold whose origin it cannot read, which is every
 * hold made before anything recorded one. The one surface the trail can still tell apart is the
 * admin's create, a booking confirmed by a user with no hold before it, and those rows are named
 * admin by one update keyed on the trail. The two CHECKs scan the table once, under a short
 * lock; neither touches booking_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table bookings
                add column source text not null default 'embed',
                add column channel text
            SQL);

        DB::unprepared('alter table bookings alter column source drop default');

        DB::unprepared(<<<'SQL'
            update bookings b set source = 'admin'
            where exists (select 1 from audit_log a where a.booking_id = b.id and a.action = 'confirmed' and a.actor like 'user:%')
              and not exists (select 1 from audit_log a where a.booking_id = b.id and a.action = 'held')
            SQL);

        DB::unprepared(<<<'SQL'
            alter table bookings
                add constraint bookings_source_check check (source in ('hosted', 'embed', 'admin')),
                add constraint bookings_channel_check
                    check (channel is null or channel in ('instagram', 'facebook', 'google', 'qr', 'other'))
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table bookings
                drop constraint bookings_channel_check,
                drop constraint bookings_source_check,
                drop column channel,
                drop column source
            SQL);
    }
};
