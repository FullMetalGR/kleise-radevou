<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The drop's central migration: what the retention sweep's last line writes on a tenant thirty
 * days after it closed, and the two platform audit actions the console's close and reopen write.
 *
 * The status closed joins the CHECK, written by the drop alone, and closed_at with it, the instant
 * the drop ended the tenant. The slug turns nullable: the drop nulls it, releasing the address for
 * a new signup, and tenants_slug_unique admits any number of nulls. A CHECK of its own,
 * tenants_closed_check, holds the three together: a closed row, and only a closed row, has a
 * closed_at and no slug, so every other row keeps the slug it always had.
 *
 * The closed row keeps its schema_name, the record of which schema the drop removed, and a new
 * signup of the released slug derives the same name from it, so the name's uniqueness narrows to
 * the rows that are not closed: tenants_schema_name_unique becomes a partial unique index of that
 * name over them. No two rows that may hold a schema ever share a name, and a closed row holds
 * none.
 *
 * The platform audit action CHECK is dropped and added again in one statement with the twelve
 * values, the pattern of migration 000019, spelled out here rather than read from
 * App\Platform\PlatformAuditAction for the reason that migration gives. Every row the table holds
 * is one of the ten the old CHECK allowed and passes the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table tenants
                alter column slug drop not null,
                add column closed_at timestamptz,
                drop constraint tenants_status_check,
                add constraint tenants_status_check
                    check (status in ('provisioning', 'active', 'suspended', 'closing', 'closed')),
                add constraint tenants_closed_check
                    check ((status = 'closed') = (closed_at is not null) and (status = 'closed') = (slug is null)),
                drop constraint tenants_schema_name_unique;

            create unique index tenants_schema_name_unique on tenants (schema_name) where status <> 'closed';

            alter table platform_audit_log
                drop constraint platform_audit_log_action_check,
                add constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended',
                    'contact_archived', 'tenant_closed', 'tenant_reopened'
                ));
            SQL);
    }

    /**
     * Back to the four statuses and the ten actions. It fails where a closed row or a row of
     * either new action has been written since, which is the answer wanted: a rollback that
     * rewrote them would rewrite the accounting record and the log.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table platform_audit_log
                drop constraint platform_audit_log_action_check,
                add constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended',
                    'contact_archived'
                ));

            drop index tenants_schema_name_unique;

            alter table tenants
                add constraint tenants_schema_name_unique unique (schema_name),
                drop constraint tenants_closed_check,
                drop constraint tenants_status_check,
                add constraint tenants_status_check
                    check (status in ('provisioning', 'active', 'suspended', 'closing')),
                drop column closed_at,
                alter column slug set not null;
            SQL);
    }
};
