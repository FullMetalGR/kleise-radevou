<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A tax id the billing provider holds for a platform customer, the business's VAT number among
 * them: its type as the provider names it (eu_vat and the others) and its value as the business
 * gave it. Nothing else of the provider's shape crosses the port.
 */
final readonly class TaxId
{
    public function __construct(
        public string $type,
        public string $value,
    ) {}
}
