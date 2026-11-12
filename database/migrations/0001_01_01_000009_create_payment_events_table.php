<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every payment notification the two webhook shapes received: the tenant payments' own record,
 * never billing's webhook_events.
 *
 * A verified notification is recorded by its provider and the provider's event id before it is
 * handled and marked processed after; a repeat finds the row and is a no-op, and a row never
 * marked is handled again on the provider's retry. A refused one, an unknown provider or token
 * or a signature that does not verify, is recorded with the provider, the route it came in on,
 * when and why, and nothing of its body: no event id, no type, no payload. The table holds no
 * payload at all, so nothing personal lands here. Kept 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table payment_events (
                id bigserial primary key,
                provider text not null,
                route text not null,
                tenant_id bigint references tenants (id) on delete set null,
                event_id text,
                type text,
                refusal text,
                processed_at timestamptz,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint payment_events_route check (route in ('own', 'platform')),
                constraint payment_events_refusal check (refusal in ('unknown_provider', 'unknown_route', 'signature')),
                constraint payment_events_verified_or_refused check ((refusal is null) = (event_id is not null) and (event_id is null) = (type is null)),
                constraint payment_events_refused_unprocessed check (refusal is null or processed_at is null),
                constraint payment_events_provider_event_unique unique (provider, event_id)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
