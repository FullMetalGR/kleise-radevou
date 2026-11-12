<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\ServicePayload;
use App\Infrastructure\Db\CatalogReader;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/v1/services and GET /api/v1/services/{id}: the active services a customer may book,
 * shaped by ServicePayload and nothing else, and the preselected-service path, which answers
 * 404 for an inactive service exactly as for a missing one, from one line, so the route is not
 * an existence oracle.
 *
 * Public by design: no key and no token on the read surface. Cross-origin by design too, for
 * the embed on the tenant's own domain. Opens no transaction.
 */
final class ServicesController extends Controller
{
    public function index(CurrentTenant $current, CatalogReader $catalog): JsonResponse
    {
        return new JsonResponse(ServicePayload::catalog($catalog->activeServices($current->get())));
    }

    /**
     * The route admits only digits. An id past what a bigint holds, or one with a leading
     * zero, names no row either, and lands on the same 404 as everything else that does not.
     */
    public function show(string $id, CurrentTenant $current, CatalogReader $catalog): JsonResponse
    {
        $number = filter_var($id, FILTER_VALIDATE_INT);
        $service = $number === false ? null : $catalog->activeService($current->get(), $number);

        if ($service === null) {
            throw new NotFoundHttpException;
        }

        return new JsonResponse(ServicePayload::of($service));
    }
}
