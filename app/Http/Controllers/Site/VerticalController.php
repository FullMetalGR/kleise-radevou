<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Brand;
use App\Site\SiteLanguage;
use App\Site\SitePages;
use App\Site\Vertical;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The six vertical pages, at /for/{vertical} and /el/for/{vertical}: one template over the
 * slots under the vertical's key in verticals.php, in the page's language, which the route's
 * locale has set, the public name filled in. The route admits the six keys alone, so any other
 * value never reaches here and is the site's 404. The closing line's signup action links the
 * form the one helper names for the page's language. No form and no script.
 */
final readonly class VerticalController
{
    public function __invoke(Request $request, SitePages $pages, Brand $brand, Factory $views, Vertical $vertical): View
    {
        return $views->make('site.vertical', [
            'copy' => $pages->copyOf(SitePages::VERTICAL, ['vertical' => $vertical->value]),
            'name' => $brand->name,
            'signup' => $pages->signup(SiteLanguage::ofPath($request->path())),
        ]);
    }
}
