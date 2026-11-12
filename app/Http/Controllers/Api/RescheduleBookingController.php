<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Actor;
use App\Http\BookingAnswer;
use App\Http\Controllers\Controller;
use App\Http\ManagedBooking;
use App\Http\Requests\RescheduleRequest;
use App\Infrastructure\BookingTransitionFactory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/bookings/{uuid}/reschedule: the customer moves every segment of their booking to
 * a new start, as one atomic release and re-hold. Partial success is impossible, so there is no
 * answer here that says half of it worked.
 *
 * The guard ran in the middleware and bound the booking it read, so the identifier this acts on
 * is one a valid credential was checked against. It is the only thing taken from that read: the
 * use case plans from the rows and decides on what its own lock finds, and the items the guard
 * saw a moment ago are not evidence of anything by the time the row is held.
 *
 * Every refusal is a typed exception the envelope answers by kind: 409 unavailable naming the
 * segment for a chain the tenant is not open to at the new start, 409 overlap naming the segment
 * for a range somebody else already holds, 409 for a status a move does not reach, 403 for a
 * window that has closed, 404 for a booking nobody has, 503 busy where a lock was not granted. A
 * 4xx here is a worded answer and the reschedule dialog shows it inside itself with the original
 * time still on screen.
 *
 * The answer is the guest view of the row the transition left, showing the new items, which
 * BookingAnswer reads back and spells.
 *
 * The actor of the trail is the customer: this route is the magic link, and a guest has no
 * account to reference, and it passes no MoveChoices, which are the business's alone. The
 * business moves bookings through the same use case from the admin, on routes of its own.
 */
final class RescheduleBookingController extends Controller
{
    public function __invoke(RescheduleRequest $request, ManagedBooking $managed, CurrentTenant $current, BookingTransitionFactory $transitions, BookingAnswer $answer): JsonResponse
    {
        $tenant = $current->get();
        $uuid = $managed->get()->uuid;

        $transitions->reschedule($tenant)->reschedule($uuid, Actor::customer(), $request->start());

        return $answer->readBack($tenant, $uuid, 'reschedule');
    }
}
