<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Demo;
use App\Site\SiteLanguage;
use App\Site\SiteLinks;
use App\Site\SitePages;
use App\Tenancy\TenantProvisioner;
use App\Tenancy\TenantUrls;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The site's home, at / and /el, its copy home.php's in the page's language and the layout around
 * it the chrome's. Its signup actions link the form the one helper names for the page's language,
 * the English form until the signup has its Greek twin. Its verticals strip links the six vertical
 * pages in the page's language, as the footer does. Its demo section mounts the embed from the
 * demo's origin and links the demo's hosted page, both spelled by TenantUrls from the demo's label
 * with no row read, so the section is in the page whether or not the demo exists. The link names
 * the page's language: the demo's row is Greek and its settings name none, so from /el it is the
 * bare path, the demo's default, and from the English home it is the /en path; the embed follows
 * the host page's
 * <html lang> on its own.
 */
final readonly class HomeController
{
    public function __invoke(Request $request, SitePages $pages, SiteLinks $links, Demo $demo, TenantUrls $urls, Factory $views): View
    {
        $language = SiteLanguage::ofPath($request->path());

        return $views->make('site.home', [
            'signup' => $pages->signup($language),
            'trialDays' => (string) TenantProvisioner::trialDays(),
            'verticals' => $links->to([SitePages::VERTICAL], $language),
            'demo' => [
                'script' => $urls->embedScript($demo->slug()),
                'page' => $urls->hostedPage($demo->slug(), $language === SiteLanguage::Greek ? null : $language),
            ],
        ]);
    }
}
