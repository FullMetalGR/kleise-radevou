<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ServedImages;
use App\Infrastructure\Storage\ImageKind;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /images/{file} on the tenant host: the cover, the gallery photos and the staff photos. The
 * name says its kind by the prefix the store wrote, and it is served only when a row of the
 * tenant holds it as that kind, through the store's lookup, with the logo route's cache, ETag
 * and nosniff. A logo's name is the logo route's alone, so one file has one URL.
 *
 * Public by design like the page that shows the images.
 */
final class TenantImageController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $current, ServedImages $served, string $file): Response
    {
        $kind = ImageKind::ofFile($file);

        if ($kind === null || $kind === ImageKind::Logo) {
            throw new NotFoundHttpException;
        }

        return $served->answer($request, $current->get(), $kind, $file);
    }
}
