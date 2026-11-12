<?php

declare(strict_types=1);

namespace App\Billing;

use App\Domain\Money\Money;

/**
 * One plan the platform sells, as the catalogue in configuration names it: the key it is spelled
 * by, the name it is shown by (Solo for the floor alone and Team for the floor plus staff,
 * config/billing.php the one home, every screen that names a plan reading it here), the provider
 * price it is sold under (never empty: the catalogue refuses a plan whose price the environment
 * leaves unset or blank), the features it grants and the monthly price the Billing screen and
 * the site's pricing page show, in minor units beside its currency.
 */
final readonly class Plan
{
    public function __construct(
        public string $key,
        public string $name,
        public string $priceId,
        public Entitlements $features,
        public Money $price,
    ) {}

    /**
     * The plan in outline, its key, its name and its features, as the catalogue's outlines name
     * a plan that cannot be sold from.
     */
    public function outline(): PlanOutline
    {
        return new PlanOutline($this->key, $this->name, $this->features);
    }
}
