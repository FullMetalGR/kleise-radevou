<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Support\HtmlString;

/**
 * What the site's layout renders around a page: the document's language, the head's title, meta
 * description, canonical and alternates, the header's wordmark, the public name beside the brand's
 * mark, its nav and language switch, and the footer's links and the entity line. A page has a
 * canonical and three alternates; the 404 has neither and carries noindex. The signup's frame
 * renders the header and the footer alone, from the same chrome. While the analytics tag's id is
 * set the chrome carries the consent banner, which both views render with its loader, and the
 * footer its cookie-settings control; while it is unset, none.
 */
final readonly class Chrome
{
    /**
     * @param  array<string, string>  $alternates  by hreflang
     * @param  list<array{href: string, label: string}>  $nav
     * @param  list<array{href: string, label: string}>  $footer
     */
    public function __construct(
        public SiteLanguage $language,
        public string $name,
        public HtmlString $mark,
        public string $title,
        public string $description,
        public ?string $canonical,
        public array $alternates,
        public bool $noindex,
        public string $home,
        public string $switchHref,
        public string $switchLabel,
        public array $nav,
        public array $footer,
        public string $entity,
        public ?ConsentBanner $consent,
    ) {}
}
