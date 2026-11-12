<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Actor;
use App\Http\BookingAnswer;
use App\Http\Controllers\Controller;
use App\Http\ManagedBooking;
use App\Infrastructure\BookingTransitionFactory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/bookings/{uuid}/cancel: the customer gives an appointment up through their magic
 * link, policy checked.
 *
 * The guard ran in the middleware and bound the booking it read, so the identifier this acts on
 * is one a valid credential was checked against. It is the only thing taken from that read: the
 * use case decides on what its own lock finds, and the status the guard saw a moment ago is not
 * evidence of anything by the time the row is held.
 *
 * Every refusal is a typed exception the envelope answers by kind: 409 for a status a cancel
 * does not reach, 403 for a window that has closed, 404 for a booking nobody has, 503 busy
 * where a lock was not granted. A 4xx here is a worded answer and the manage view shows it
 * politely on the booking the customer is looking at.
 *
 * The answer is the guest view of the row the transition left, which BookingAnswer reads back
 * and spells.
 *
 * The actor of the trail is the customer: this route is the magic link, and a guest has no
 * account to reference. The admin's own cancellations carry the user who made them, which is
 * the same use case with a different actor.
 */
final class CancelBookingController extends Controller
{
    public function __invoke(ManagedBooking $managed, CurrentTenant $current, BookingTransitionFactory $transitions, BookingAnswer $answer): JsonResponse
    {
        $tenant = $current->get();
        $uuid = $managed->get()->uuid;

        $transitions->cancel($tenant)->cancel($uuid, Actor::customer());

        return $answer->readBack($tenant, $uuid, 'cancel');
    }
}
