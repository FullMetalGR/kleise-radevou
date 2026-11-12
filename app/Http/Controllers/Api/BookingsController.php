<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\BookingAnswer;
use App\Http\Controllers\Controller;
use App\Http\ManagedBooking;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/bookings/{uuid}: the guest self-service read, and the first route behind the
 * manage token.
 *
 * The answer is the guest view of the payload whitelist, the same shape the hold answered with
 * less the manage token, which exists once and is never fetched again. The customer sees their
 * own name, email and phone, because the booking is theirs; they do not see the rejection
 * reason, which is the owner's note to their own staff, and the status sentence is what the
 * customer is told instead.
 *
 * The shape and the no-store header are BookingAnswer's, shared with the routes that answer the
 * same view after moving the booking rather than after reading it.
 *
 * The guard ran in the middleware and its answer is bound, so no statement is issued here. No
 * transaction is opened: this is a read, and the two statements behind it name their schema.
 * The general limit applies, 300 a minute per IP, and the route is permissively cross-origin
 * like the rest of this API, for the embed on a tenant's own site.
 */
final class BookingsController extends Controller
{
    public function __invoke(ManagedBooking $managed, BookingAnswer $answer): JsonResponse
    {
        return $answer->of($managed->get());
    }
}
