<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\TenantContext;
use DateTimeZone;

/**
 * A tenant context with the zone and the currency a test names, Athens and euros unless it
 * says otherwise.
 */
final readonly class FixedTenant implements TenantContext
{
    public function __construct(private string $zone = 'Europe/Athens', private string $currency = 'EUR') {}

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->zone);
    }

    public function currencyCode(): string
    {
        return $this->currency;
    }
}
