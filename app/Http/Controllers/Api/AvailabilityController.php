<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\AvailabilityPayload;
use App\Http\AvailabilityRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicAvailabilityRequest;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\Db\SettingsReader;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/availability: the instants at which the whole chain the query describes can
 * start, on each day asked for. Advisory: a start offered here may be gone 200ms later, and the
 * hold is the only authority. So the answer is never cacheable, no-store, stale the moment it
 * is sent.
 *
 * The engine's answer, AvailabilityRead, thinned to the starts on the tenant's start interval,
 * read on the tenant's wall clock: the booking app offers a start every start_interval_min
 * rather than at every free step of the grid. It shapes the offer and nothing else, so a hold
 * at a free start off the interval is still taken. The admin's one JSON endpoint shares the
 * request and the answer's shape, and answers the engine's answer whole. Opens no transaction.
 *
 * A window whose every day is empty is answered with next_available_from beside its days, the
 * first later day holding a start on the interval inside the chain's smallest horizon, or null:
 * the booking app's Next available date. It is read forward through the same masks and cache,
 * only for an empty window, so a window with a start costs what it did. The admin's endpoint
 * never carries it.
 *
 * Public by design: no key and no token on the read surface. Rate limited on its own, in place
 * of the general limit and not beside it.
 */
final class AvailabilityController extends Controller
{
    public function __invoke(PublicAvailabilityRequest $request, CurrentTenant $current, AvailabilityRead $availability, SettingsReader $settings): JsonResponse
    {
        $tenant = $current->get();
        $query = $request->toQuery();
        $interval = $settings->read($tenant)->startIntervalMin;
        $starts = $availability->query($tenant, $query, Pool::Online)->every($interval, $tenant->zone());
        $payload = $starts->firstDayWithAStart() === null
            ? AvailabilityPayload::emptyWindow($starts, $availability->nextAvailableFrom($tenant, $query, Pool::Online, $interval))
            : AvailabilityPayload::of($starts);

        $response = new JsonResponse($payload);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
