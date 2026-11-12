<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;

/**
 * The owner's acceptance of the terms at signup as the console's detail reads it: the instant
 * the reservation recorded and the version accepted, a Y-m-d day, the two set together or null
 * together by tenants_terms_check.
 */
final readonly class TenantTerms
{
    public function __construct(
        public DateTimeImmutable $at,
        public string $version,
    ) {}
}
