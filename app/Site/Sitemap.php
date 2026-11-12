<?php

declare(strict_types=1);

namespace App\Site;

use XMLWriter;

/**
 * The sitemap's XML: one <url> per page per language, each its <loc> and the three xhtml:link
 * alternates of 1.2, the pages the registry reads from the router, and a page served once per
 * key, the six verticals, once per key in each language. No lastmod: the copy is deployed like
 * code and a date here would be one more thing to keep true.
 */
final readonly class Sitemap
{
    private const string NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const string XHTML = 'http://www.w3.org/1999/xhtml';

    public function __construct(private SitePages $pages) {}

    public function xml(): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('    ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', self::NAMESPACE);
        $xml->writeAttribute('xmlns:xhtml', self::XHTML);

        foreach ($this->pages->indexed() as $page) {
            foreach ($this->pages->instances($page) as $parameters) {
                $alternates = $this->pages->alternates($page, $parameters);

                foreach (SiteLanguage::cases() as $language) {
                    $xml->startElement('url');
                    $xml->writeElement('loc', $this->pages->url($page, $language, $parameters));

                    foreach ($alternates as $hreflang => $href) {
                        $xml->startElement('xhtml:link');
                        $xml->writeAttribute('rel', 'alternate');
                        $xml->writeAttribute('hreflang', $hreflang);
                        $xml->writeAttribute('href', $href);
                        $xml->endElement();
                    }

                    $xml->endElement();
                }
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
