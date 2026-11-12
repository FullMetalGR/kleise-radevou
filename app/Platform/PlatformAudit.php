<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Database\Connection;

/**
 * The platform audit log's one writer: one insert on the connection it is handed, so the
 * transaction of the action it records, where there is one, carries its row and rolls it back
 * with the action. The operator and the tenant are ids, the tenant null for an action about
 * none, and the entry has already checked its payload's keys and values; created_at is left to
 * the column's default, the transaction's clock reading.
 */
final readonly class PlatformAudit
{
    public static function record(Connection $db, int $operatorId, PlatformAuditEntry $entry, ?int $tenantId): void
    {
        $db->insert('insert into platform_audit_log (operator_id, action, tenant_id, payload) values (?, ?, ?, ?::jsonb)', [
            $operatorId,
            $entry->action->value,
            $tenantId,
            // An object even when empty, so a payload with no facts is {} as the column's default
            // is.
            json_encode((object) $entry->payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
