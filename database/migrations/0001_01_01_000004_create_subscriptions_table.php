<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The billing provider's record of a tenant's subscription, kept apart from the resolved facts on
 * the tenant row. One row per tenant, written only by the subscription sync.
 *
 * The customer comes first: signup creates the provider's customer before the checkout session
 * and records it here, so the first event finds its tenant by customer. The subscription's own
 * identifier, its status, its period end and its items stay null or empty until the first
 * snapshot names them. The customer is unique as well as the subscription, because it is what an
 * event is resolved to a tenant by, and two tenants answering to one customer would be a tenant
 * charged for another's plan.
 *
 * The items are the line items as last seen, so a resolve can be replayed without a network call.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table subscriptions (
                id bigserial primary key,
                tenant_id bigint not null references tenants (id),
                provider_customer_id text not null,
                provider_subscription_id text,
                status text,
                current_period_end timestamptz,
                items jsonb not null default '[]',
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint subscriptions_tenant_id_unique unique (tenant_id),
                constraint subscriptions_provider_customer_id_unique unique (provider_customer_id),
                constraint subscriptions_provider_subscription_id_unique unique (provider_subscription_id)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
