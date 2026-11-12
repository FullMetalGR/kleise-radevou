<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeZone;

/**
 * What a use case needs to know about the tenant it runs for, and nothing more: the zone its
 * staff-days are counted in and the currency its prices carry. A use case never learns which
 * tenant that is; the tenant row implements this, and the repository it is paired with is
 * already bound to the same tenant.
 */
interface TenantContext
{
    public function zone(): DateTimeZone;

    public function currencyCode(): string;
}
