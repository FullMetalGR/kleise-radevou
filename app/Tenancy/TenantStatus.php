<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * A row is provisioning from the moment a signup reserves it, or the console claims it, until its
 * build creates the schema. A row is closed once the retention sweep's drop has removed its schema
 * thirty days after its closing, its slug released and the row kept as the accounting record.
 * Mirrors the CHECK constraint on tenants.status.
 */
enum TenantStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closing = 'closing';

    /** Dropped by the retention sweep: no schema, no slug, no users; written by the drop alone. */
    case Closed = 'closed';

    /**
     * The statuses of a row with no schema to open: provisioning, whose build has not committed,
     * and closed, whose schema the drop removed. Every loop over the tenants that opens a schema,
     * or queues a job that does, reads past these, through the model's built().
     *
     * @return list<self>
     */
    public static function schemaless(): array
    {
        return [self::Provisioning, self::Closed];
    }
}
