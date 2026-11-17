<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The console's three platform audit actions on a business's help ticket: help_ticket_replied, an
 * operator answering it, and help_ticket_closed and help_ticket_reopened, an operator moving its
 * status. Each row names the business in tenant_id and the ticket by its id under the payload key
 * ticket, and holds nothing of a message nor any address. That key is
 * App\Platform\PlatformAuditKey's and needs nothing here, since a jsonb column cannot constrain its
 * own keys.
 *
 * The CHECK is dropped and added again with the fifteen values, spelled out here rather than read
 * from App\Platform\PlatformAuditAction for the reason migration 000014 gives. The table is live
 * and every console sign-in writes it, so the change is made in the form that holds no lock for
 * long: the statement that drops the old CHECK adds the new one not valid, which takes the
 * table's lock for an instant and reads no row, and a statement of its own then validates it,
 * reading the rows under a lock that lets reads and writes go on. Neither runs inside a
 * transaction, so the first statement's lock is gone before the second reads. From the first
 * statement on, every row written is held to the new CHECK; every row already there is one of the
 * twelve the old CHECK allowed, and the validation finds it among the fifteen.
 */
return new class extends Migration
{
    /** @var bool */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            alter table platform_audit_log
                drop constraint platform_audit_log_action_check,
                add constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended',
                    'contact_archived', 'tenant_closed', 'tenant_reopened',
                    'help_ticket_replied', 'help_ticket_closed', 'help_ticket_reopened'
                )) not valid
            SQL);

        DB::unprepared('alter table platform_audit_log validate constraint platform_audit_log_action_check');
    }

    /**
     * Back to the twelve. It fails where a row of a help ticket action has been written since,
     * which is the answer wanted: a rollback that dropped those rows would rewrite the log.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            alter table platform_audit_log
                drop constraint platform_audit_log_action_check,
                add constraint platform_audit_log_action_check check (action in (
                    'sign_in', 'sign_out', 'tenant_suspended', 'tenant_resumed', 'tenant_created',
                    'features_changed', 'password_link_sent', 'support_opened', 'support_ended',
                    'contact_archived', 'tenant_closed', 'tenant_reopened'
                ))
            SQL);
    }
};
