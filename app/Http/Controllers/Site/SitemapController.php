<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Sitemap;
use Illuminate\Contracts\Routing\ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /sitemap.xml: the site's pages in both languages with their alternates, built from the
 * route registry. Submitted to the search engines by hand at launch.
 */
final readonly class SitemapController
{
    public function __invoke(Sitemap $sitemap, ResponseFactory $responses): Response
    {
        return $responses->make($sitemap->xml(), Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
