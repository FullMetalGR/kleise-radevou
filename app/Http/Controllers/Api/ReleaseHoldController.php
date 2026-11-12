<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\ManagedBooking;
use App\Infrastructure\BookingTransitionFactory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Response;

/**
 * DELETE /api/v1/holds/{uuid}: the customer hands a checkout hold back and the slot is released
 * immediately.
 *
 * The token travels in the query string, because a DELETE carries no body. The guard in front
 * reads it there and answers every failed credential as the one 404.
 *
 * The answer is 204 and no body. There is nothing to say: the customer is leaving the page or
 * going back to pick another time, and the booking they had is gone. A refusal is 409
 * not_releasable in the envelope, which the client ignores, because the release is best effort
 * from its side and authoritative from ours: the sweeper is the real guarantee and the page may
 * be unloading as the answer arrives.
 *
 * It sits under the general limit rather than the write family's twenty a minute. The browser
 * sends this on page hide with keepalive, the count is the customer's own, and a customer who
 * opened and abandoned a few tabs must not find their next hold refused.
 */
final class ReleaseHoldController extends Controller
{
    public function __invoke(ManagedBooking $managed, CurrentTenant $current, BookingTransitionFactory $transitions): Response
    {
        $transitions->release($current->get())->release($managed->get()->uuid);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
