<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The platform audit log: every operator action as a row, the operator by id, the action from a
 * closed vocabulary, the tenant by id where the action is about one, a payload under a closed key
 * list, and the instant. A row records what an operator did and is never rewritten, so there is a
 * created_at and no updated_at.
 *
 * No name and no address, ever, as the tenant trail's rule has it: the operator, the tenant and a
 * user are references. The action CHECK is the structural half, the nine values of
 * App\Platform\PlatformAuditAction spelled out here rather than read from it, because a shipped
 * migration must not change when a constant does; CentralSchemaTest inserts every case, so the two
 * cannot drift. The payload's half is App\Platform\PlatformAuditKey, held by the net over every
 * row the suites write, because a jsonb column cannot constrain its own keys.
 *
 * tenant_id references tenants with no cascade: a closed tenant's central row is kept for
 * accounting, so a row about a tenant keeps naming it. The indexes serve the two reads of the
 * Audit screen: one tenant's rows newest first, and every row newest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create table platform_audit_log (
                id bigserial primary key,
                operator_id bigint not null references operators (id),
                action text not null,
                tenant_id bigint references tenants (id),
                payload jsonb not null default '{}'::jsonb,
                created_at timestamptz not null default now(),
                constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended'
                ))
            )
            SQL);

        DB::unprepared('create index platform_audit_log_tenant on platform_audit_log (tenant_id, created_at)');
        DB::unprepared('create index platform_audit_log_created on platform_audit_log (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_log');
    }
};
