<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Site\SiteLanguage;

/**
 * A tenant host page's addresses in its two languages, TenantPages::addresses: the language the
 * request is answered in, where its language switch leads, its hreflang pair by language, the
 * default's page at its bare path, and its canonical. Computed and rendered by nothing yet.
 */
final readonly class PageAddresses
{
    /**
     * @param  array<string, string>  $alternates  each language's address by its value, en first
     */
    public function __construct(
        public SiteLanguage $language,
        public string $switchHref,
        public array $alternates,
        public string $canonical,
    ) {}
}
