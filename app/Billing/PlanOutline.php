<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * One plan in outline, as the catalogue in configuration names it: the key it is spelled by, the
 * name it is shown by, Solo or Team (config/billing.php the one home), and the features it
 * grants, read without the price it is sold under or the amount it displays. What a page names
 * the plans by where the catalogue cannot sell them, a price identifier unset as in development:
 * the site's pricing page shows its cards with no amount and the console still names the plans.
 * Nothing that charges or gates reads it; they read Plan.
 */
final readonly class PlanOutline
{
    public function __construct(
        public string $key,
        public string $name,
        public Entitlements $features,
    ) {}
}
