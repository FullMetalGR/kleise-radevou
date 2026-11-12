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
 * POST /api/v1/bookings/{uuid}/confirm: the customer settles their own hold, on the free and
 * pay-on-site path.
 *
 * The guard ran in the middleware and bound the booking it read, so the identifier this acts on
 * is one a valid credential was checked against. It is the only thing taken from that read: the
 * use case decides on what its own lock finds, and the status the guard saw a moment ago is not
 * evidence of anything by the time the row is held.
 *
 * Every refusal is a typed exception the envelope answers by kind: 410 for a hold that ran out,
 * 409 for a booking that is no longer waiting to be confirmed, 402 for one that must be paid for
 * first, 503 busy where a lock was not granted. A 4xx here is a worded answer and the booking
 * app shows it politely.
 *
 * The answer is the guest view of the row the transition left, which BookingAnswer reads back
 * and spells.
 *
 * The credential the guard accepted goes to the use case beside the identifier, and travels no
 * further than the confirmation's own listener. It is the second of the two moments the
 * plaintext exists, and the customer's magic link has nowhere else to come from once the
 * response has been written. A request a My bookings session admitted presented no token, so the
 * use case is handed none and the confirmation says where the customer's link is, as it does
 * where no token was seen.
 *
 * The actor of the trail is the customer: this route is the booking page and the magic link, and
 * a guest has no account to reference. The admin's own confirmations carry the user who made
 * them.
 */
final class ConfirmBookingController extends Controller
{
    public function __invoke(ManagedBooking $managed, CurrentTenant $current, BookingTransitionFactory $transitions, BookingAnswer $answer): JsonResponse
    {
        $tenant = $current->get();
        $uuid = $managed->get()->uuid;

        $transitions->confirm($tenant)->confirm($uuid, Actor::customer(), $managed->token()?->plain);

        return $answer->readBack($tenant, $uuid, 'confirm');
    }
}
