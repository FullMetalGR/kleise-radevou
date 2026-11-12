<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Tenancy\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A tenant's audit trail: kept 24 months from created_at and then deleted outright, since it
 * carries no personal data, whatever the action, the actor or the booking it names. The booking
 * it names may outlive it, as the revision of a booking's calendar file is read from its items
 * for that reason (BookingReader::revision).
 */
final readonly class AuditLogLine implements TenantLine
{
    /** How long a trail row is kept from its created_at. */
    public const string KEPT = 'P24M';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'audit_log';
    }

    public function apply(Tenant $tenant): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            sprintf('delete from %s.audit_log where created_at < ?::timestamptz', $tenant->schema_name),
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
