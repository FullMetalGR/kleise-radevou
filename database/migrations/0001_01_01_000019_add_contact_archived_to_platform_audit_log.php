<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The tenth platform audit action: contact_archived, an operator archiving a message of the site's
 * contact form, its payload the one key message holding the row's id and nothing of its content.
 * That key is App\Platform\PlatformAuditKey's and needs nothing here, since a jsonb column cannot
 * constrain its own keys.
 *
 * The CHECK is dropped and added again in one statement with the tenth value, so the table is
 * never without it. The values are spelled out here rather than read from
 * App\Platform\PlatformAuditAction, for the reason migration 000014 gives: a shipped migration
 * must not change when a constant does, and CentralSchemaTest inserts every case of the enum, so
 * the two cannot drift.
 *
 * Adding the CHECK scans platform_audit_log once to validate the rows it holds, every one of which
 * is one of the nine the old CHECK allowed and so passes the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table platform_audit_log
                drop constraint platform_audit_log_action_check,
                add constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended',
                    'contact_archived'
                ))
            SQL);
    }

    /**
     * Back to the nine. It fails where a contact_archived row has been written since, which is
     * the answer wanted: a rollback that dropped those rows would rewrite the log.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table platform_audit_log
                drop constraint platform_audit_log_action_check,
                add constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended'
                ))
            SQL);
    }
};
