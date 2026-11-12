<?php

declare(strict_types=1);

namespace App\Platform;

use App\Billing\Entitlements;
use App\Site\SiteLanguage;
use DateTimeZone;

/**
 * What the console's form names for a free tenant: the business, its clock, its currency and its
 * kind or none, the owner, the features the operator chose, and the language the platform writes
 * to the tenant's people in, tenants.locale, which its set-password mail follows. The slug is
 * still input here, judged by the form through SlugRules and again by the provisioner. No
 * password is named: the owner sets their own through the set-password link the creation mails.
 */
final readonly class NewFreeTenant
{
    public function __construct(
        public string $slug,
        public string $name,
        public DateTimeZone $timezone,
        public string $currency,
        public ?string $category,
        public string $ownerName,
        public string $ownerEmail,
        public Entitlements $features,
        public SiteLanguage $locale,
    ) {}
}
