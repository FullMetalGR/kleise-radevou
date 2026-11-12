<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Billing\Entitlements;
use App\Site\SiteLanguage;
use DateTimeZone;

/**
 * What provisioning needs to know: the details a signup collects, whichever caller collected them.
 * The slug is still input here; the provisioner judges it through SlugRules. The timezone arrives
 * as a DateTimeZone because that is the one thing the database stores blind and every screen later
 * depends on.
 *
 * The entitlements are what the new row starts with. The console command, the development and demo
 * path that bypasses billing, names them with --features; a caller naming none starts the tenant
 * on the floor, and the subscription sync is the one writer afterwards. The category is the
 * business's kind as the signup form names it, one of the seven the table allows; the console
 * names none. The locale is the language the signup was made in, written on the row as
 * tenants.locale: the form's route's, English where a caller names none. The terms are the owner's
 * acceptance the signup form's box records, null from every other caller: the console's free
 * tenant form, tenant:provision and demo:reset.
 */
final readonly class Signup
{
    public function __construct(
        public string $slug,
        public string $name,
        public DateTimeZone $timezone,
        public string $currency,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPassword,
        public ?Entitlements $entitlements = null,
        public ?string $category = null,
        public SiteLanguage $locale = SiteLanguage::English,
        public ?TermsAcceptance $terms = null,
    ) {}
}
