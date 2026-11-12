<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Booking\Actor;
use App\Http\AvailabilityPayload;
use App\Http\AvailabilityRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\WizardAvailabilityRequest;
use App\Infrastructure\Db\Pool;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/api/availability: the wizard's admin JSON endpoint, for the manual booking wizard that
 * opens over any screen and re-queries as the operator changes the day or the chain. The public
 * read's request, its 400 sentences and its answer's shape, advisory and no-store as that read is,
 * over the session.
 *
 * It answers every start on the granularity grid, the engine's answer whole, and not only those on
 * the start interval: the interval shapes what customers are offered, and the operator placing a
 * booking by hand may use any free start. It answers for the business, so a start inside the lead
 * time or on a day past the horizon is offered, those binding customers alone; only a start already
 * passed is gone. What it offers the create endpoint accepts. Opens no transaction.
 *
 * Admits manageBookings or viewOwnCalendar, never gated by the subscription and carrying no
 * limit; the route declares all three.
 */
final class WizardAvailabilityController extends Controller
{
    public function __invoke(WizardAvailabilityRequest $request, CurrentTenant $current, AvailabilityRead $availability): JsonResponse
    {
        $response = new JsonResponse(AvailabilityPayload::of($availability->query($current->get(), $request->toQuery(), Pool::Whole, Actor::Admin)));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
