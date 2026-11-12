<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Brand;
use App\Site\LegalFrame;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;

/**
 * The legal pages: the terms of service, the privacy policy, the cookie notice and the data
 * processing agreement, each a page of the site in both languages rendered by the one template
 * over the language file its route names, legal/terms for /terms and the rest: the title, the
 * version date, and the sections, each a heading over paragraphs and lists, the public name
 * filled in. The page's language is its route's.
 *
 * Each carries the entity line, and while the texts are unreviewed the draft notice at its top,
 * both from the legal frame, read from configuration on every page served.
 */
final readonly class LegalController
{
    public function __invoke(Request $request, SitePages $pages, LegalFrame $legal, Brand $brand, Factory $views): View
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if ($name === null) {
            throw new LogicException('A legal page is rendered for a named page route alone.');
        }

        return $views->make('site.legal', [
            'copy' => $pages->copyOf(SiteLanguage::pageOf($name)),
            'name' => $brand->name,
            'entity' => $legal->entity(SiteLanguage::ofRouteName($name)),
            'draft' => ! $legal->reviewed(),
        ]);
    }
}
