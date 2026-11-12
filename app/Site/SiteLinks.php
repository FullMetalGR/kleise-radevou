<?php

declare(strict_types=1);

namespace App\Site;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;

/**
 * The site's links to its own pages: the header's nav, the footer and the home's verticals strip
 * (3.1, 3.5). A page is linked once per parameter set the registry serves it under, so a
 * vertical once per key, each at its address in the language asked for and labelled from
 * site.php's pages in that language: by its route's name after site., and for a page served once
 * per key, under that by the key, so site.pages.vertical.barbers. A label may name the public
 * name as :name.
 */
final readonly class SiteLinks
{
    public function __construct(private SitePages $pages, private Translator $translator, private Brand $brand) {}

    /**
     * @param  list<string>  $pages
     * @return list<array{href: string, label: string}>
     */
    public function to(array $pages, SiteLanguage $language): array
    {
        $links = [];

        foreach ($pages as $page) {
            foreach ($this->pages->instances($page) as $parameters) {
                $key = implode('.', ['site.pages', Str::after($page, 'site.'), ...array_values($parameters)]);
                $label = $this->translator->get($key, ['name' => $this->brand->name], $language->value);

                $links[] = ['href' => $this->pages->url($page, $language, $parameters), 'label' => is_string($label) ? $label : $key];
            }
        }

        return $links;
    }
}
