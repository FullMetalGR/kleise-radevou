<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Which tenant a payment notification is for. Both webhook shapes arrive on the central host,
 * which cannot see a tenant's schema until it knows the tenant: own mode names its connection by
 * the token in the path, platform mode by the connected account in the payload. One row per
 * connection that has either, written in the same transaction as the connection itself and
 * removed with it.
 *
 * A tenant row deleted takes its routes with it.
 *
 * It holds no credential. The token is a routing name, not a secret: a notification sent to it
 * is still verified against the connection's own signing secret and refused when it is not
 * signed with it, so knowing the token grants nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table payment_routes (
                id bigserial primary key,
                tenant_id bigint not null references tenants (id) on delete cascade,
                provider text not null,
                webhook_token text,
                account_ref text,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint payment_routes_one_name check (num_nonnulls(webhook_token, account_ref) = 1),
                constraint payment_routes_provider_token_unique unique (provider, webhook_token),
                constraint payment_routes_provider_account_unique unique (provider, account_ref)
            );
            create index payment_routes_tenant_id on payment_routes (tenant_id);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_routes');
    }
};
