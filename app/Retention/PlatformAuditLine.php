<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The platform audit log: kept twenty-four months from created_at, as the tenant trail is, then
 * deleted outright. A suspension's payload.reason, the one possibly personal value, goes with
 * its row.
 */
final readonly class PlatformAuditLine implements RetentionLine
{
    /** How long a row is kept from its created_at. */
    public const string KEPT = 'P24M';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'platform_audit_log';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            'delete from platform_audit_log where created_at < ?::timestamptz',
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
