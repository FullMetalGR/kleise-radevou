<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every verified notification from the billing provider, recorded by the provider's event id
 * before it is handled. The event id is the idempotency key: a delivery whose id is recorded
 * and processed is a no-op, and one recorded but never marked, because an earlier attempt
 * failed midway, is handled again on the provider's retry.
 *
 * A notification whose signature cannot be verified is never recorded here; it is logged and
 * answered 400. The payload is the body as the provider signed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table webhook_events (
                id bigserial primary key,
                provider_event_id text not null,
                type text not null,
                payload jsonb not null,
                processed_at timestamptz,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint webhook_events_provider_event_id_unique unique (provider_event_id)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
