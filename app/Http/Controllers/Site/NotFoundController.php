<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\SiteLanguage;
use App\Site\SitePages;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The central host's 404: a path the host does not know answers 404 with a page in the site's
 * layout, Greek under /el and English elsewhere, noindex, linking the home in its language. The
 * tenant and console hosts keep their own.
 */
final readonly class NotFoundController
{
    public function __invoke(Request $request, SitePages $pages, ResponseFactory $responses): Response
    {
        return $responses->view('site.not-found', ['home' => $pages->url(SitePages::HOME, SiteLanguage::ofPath($request->path()))], Response::HTTP_NOT_FOUND);
    }
}
