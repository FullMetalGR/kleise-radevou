<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ServedImages;
use App\Infrastructure\Storage\ImageKind;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /logo/{file} on the tenant host: the tenant's logo as the hosted page's header loads it.
 * The file is served only under the name the tenant's own settings row holds, found through
 * the store's lookup, so a stale URL after a replacement is a 404 rather than the old image,
 * and one tenant's file is never answered under another's origin; the answer is the one every
 * tenant image gets, a year's cache and an ETag.
 *
 * Public by design like the page that embeds it.
 */
final class BrandLogoController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $current, ServedImages $served, string $file): Response
    {
        return $served->answer($request, $current->get(), ImageKind::Logo, $file);
    }
}
