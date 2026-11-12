<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Storage\ImageKind;
use App\Infrastructure\Storage\ImageLookup;
use App\Infrastructure\Storage\TenantImages;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A tenant's image answered to a browser, the one way for the logo at GET /logo/{file} and the
 * cover, the gallery and the staff photo at GET /images/{file}. A name the store's lookup does
 * not find held as the kind by a row of the tenant answers 404, so a stale URL after a
 * replacement is a 404 rather than the old image and one tenant's file is never answered under
 * another's origin. The name carries a hash of the bytes, so the answer is cached for a year and
 * a replaced image is a new URL; the ETag answers a revalidation with a 304, and nosniff holds a
 * browser to the type the store read from the bytes. A held name the disk no longer has answers
 * 404 and says so in the log.
 */
final readonly class ServedImages
{
    /** A year: the URL changes with the bytes, so nothing served under it ever goes stale. */
    public const int MAX_AGE = 31536000;

    public function __construct(
        private ImageLookup $lookup,
        private TenantImages $images,
        private LoggerInterface $log,
    ) {}

    public function answer(Request $request, Tenant $tenant, ImageKind $kind, string $file): Response
    {
        $path = $this->lookup->find($tenant, $kind, $file);

        if ($path === null) {
            throw new NotFoundHttpException;
        }

        $image = $this->images->read($tenant, $path);

        if ($image === null) {
            $this->log->error(sprintf('The %s image of tenant "%s" is recorded at %s and is not on the %s disk.', $kind->value, $tenant->slug, $path, TenantImages::DISK));

            throw new NotFoundHttpException;
        }

        $response = new Response($image['contents'], Response::HTTP_OK, ['Content-Type' => $image['mime'], 'X-Content-Type-Options' => 'nosniff']);
        $response->setEtag(hash('xxh128', $image['contents']));
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
        $response->isNotModified($request);

        return $response;
    }
}
