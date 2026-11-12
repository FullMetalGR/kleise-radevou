<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;

/**
 * One row of the platform audit log as the Audit screen reads it: the entry checked again on the
 * way out, the operator by name and the tenant by slug as their tables hold them, null for an
 * action about no tenant, and the instant it was written.
 */
final readonly class PlatformAuditRecord
{
    public function __construct(
        public int $id,
        public PlatformAuditEntry $entry,
        public string $operatorName,
        public ?string $tenantSlug,
        public DateTimeImmutable $createdAt,
    ) {}
}
