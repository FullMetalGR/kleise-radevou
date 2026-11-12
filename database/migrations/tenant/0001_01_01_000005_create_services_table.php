<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A thing a customer can book: a duration, a price, buffers and booking rules. No service type
 * and no capacity column: every service generates its slots from staff availability and every
 * item consumes exactly one staff member, so a column with one possible value is a column
 * somebody eventually branches on.
 *
 * The three enumerations are text under a CHECK, as every table here has them, and every count
 * is checked too: the domain's ServiceWindows and ChainSegment refuse a negative value, and the
 * constraint keeps a hand-edited row from ever reaching them. processing_gap_min is named for
 * what it is: the staff member is free during it.
 *
 * currency is inherited from the tenant at creation and never rewritten by an edit, so a change
 * of the tenant's default cannot restate a price a customer already saw. description defaults
 * to empty rather than null so the public list always carries a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table services (
                id bigserial primary key,
                name text not null,
                description text not null default '',
                duration_min integer not null,
                processing_gap_min integer not null default 0,
                buffer_before_min integer not null default 0,
                buffer_after_min integer not null default 0,
                price_minor bigint not null default 0,
                currency char(3) not null,
                payment_mode text not null,
                requires_approval boolean not null default false,
                approval_hold_hours integer,
                on_approval_timeout text not null default 'expire',
                cancel_window_hours integer not null default 24,
                reschedule_window_hours integer not null default 24,
                lead_time_min integer not null default 0,
                horizon_days integer not null default 60,
                status text not null,
                sort integer not null default 0,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint services_payment_mode_check check (payment_mode in ('free', 'online', 'onsite')),
                constraint services_on_approval_timeout_check check (on_approval_timeout in ('expire', 'auto_approve')),
                constraint services_status_check check (status in ('active', 'inactive')),
                constraint services_duration_min_check check (duration_min > 0),
                constraint services_processing_gap_min_check check (processing_gap_min >= 0),
                constraint services_buffer_before_min_check check (buffer_before_min >= 0),
                constraint services_buffer_after_min_check check (buffer_after_min >= 0),
                constraint services_price_minor_check check (price_minor >= 0),
                constraint services_cancel_window_hours_check check (cancel_window_hours >= 0),
                constraint services_reschedule_window_hours_check check (reschedule_window_hours >= 0),
                constraint services_lead_time_min_check check (lead_time_min >= 0),
                constraint services_horizon_days_check check (horizon_days >= 0),
                constraint services_approval_hold_hours_check
                    check (approval_hold_hours is null or approval_hold_hours > 0)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
