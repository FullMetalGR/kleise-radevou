<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The row every request resolves before it touches anything else. Written as DDL rather than
 * through the schema builder because the builder has no citext, no text[] and no CHECK, and the
 * table is specified down to those.
 *
 * The defaults make a tenant created without a subscription active and on the floor: status
 * active, an empty entitlement set, subscription state active. The signup flow overrides them
 * explicitly when it creates a row at checkout time; the subscription sync is the only writer of
 * the billing columns after that.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('create extension if not exists citext');

        DB::unprepared(<<<'SQL'
            create table tenants (
                id bigserial primary key,
                slug citext not null,
                name text not null,
                schema_name text not null,
                timezone text not null,
                currency char(3) not null,
                status text not null default 'active',
                entitlements text[] not null default '{}',
                narrowed text[] not null default '{}',
                plan_ref text,
                subscription_state text not null default 'active',
                grace_ends_at timestamptz,
                closing_at timestamptz,
                created_at timestamptz not null default now(),
                updated_at timestamptz not null default now(),
                constraint tenants_slug_unique unique (slug),
                constraint tenants_schema_name_unique unique (schema_name),
                constraint tenants_status_check
                    check (status in ('provisioning', 'active', 'suspended', 'closing')),
                constraint tenants_subscription_state_check
                    check (subscription_state in ('active', 'grace', 'past_due', 'canceled', 'incomplete'))
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
