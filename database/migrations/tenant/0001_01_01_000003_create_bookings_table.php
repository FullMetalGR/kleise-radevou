<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The container. Status lives here and nowhere else; items inherit it. The nine states are the
 * status machine, and the three hold classes are stored rather than derived from the TTL
 * because the TTL cannot be read backwards into the reason, and the reason decides which
 * acknowledgement the customer gets.
 *
 * payment_mode and requires_approval are copied from the service at hold time, so a later edit
 * to the service cannot restate what the customer agreed to. manage_token_hash holds only the
 * hash. The index on (status, hold_expires_at) serves the sweeper; the one on customer_email
 * serves erasure.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table bookings (
                id bigserial primary key,
                uuid uuid not null,
                status text not null,
                hold_class text,
                hold_expires_at timestamptz,
                customer_name text not null,
                customer_email citext not null,
                customer_phone text,
                total_minor bigint not null,
                currency char(3) not null,
                payment_mode text not null,
                requires_approval boolean not null,
                approved_at timestamptz,
                approved_by bigint,
                rejection_reason text,
                manage_token_hash text not null,
                payment_ref text,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint bookings_uuid_unique unique (uuid),
                constraint bookings_status_check check (status in (
                    'pending', 'awaiting_approval', 'awaiting_payment', 'confirmed',
                    'completed', 'no_show', 'cancelled', 'rejected', 'expired'
                )),
                constraint bookings_hold_class_check
                    check (hold_class is null or hold_class in ('checkout', 'approval', 'payment')),
                constraint bookings_payment_mode_check
                    check (payment_mode in ('free', 'online', 'onsite'))
            )
            SQL);

        DB::unprepared('create index bookings_sweeper on bookings (status, hold_expires_at)');
        DB::unprepared('create index bookings_customer_email on bookings (customer_email)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
