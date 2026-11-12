<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingSnapshot;
use App\Domain\Booking\ManageToken;
use LogicException;

/**
 * The booking a request's manage token unlocked, for the controller that runs after the guard.
 * The guard reads the booking to decide whether the credential is good for it, so the answer is
 * already in hand; a controller reading it again would issue the same two statements to learn
 * the same thing, and could read a different row than the one the credential was checked
 * against.
 *
 * The token the guard verified is bound beside it, because a verified request is one of the two
 * moments the plaintext exists at all: it is minted inside HoldBooking and it is presented
 * here, and only its hash is stored, so a route that wants to put the customer's magic link in
 * an email has nowhere else to read one from. It is held rather than re-read from the request
 * so that the value a route uses is the one the comparison was made against.
 *
 * A request a My bookings session admitted presented no token, and binds none: the routes that
 * hand a token on take null for it, as the signed road does.
 *
 * Bound for exactly the span of one request, the way the tenant is: the middleware binds it
 * before the route runs and releases it after, whatever the route did, so a process serving
 * many requests in a row can never hand one request the booking of an earlier one. Reading it
 * where nothing is bound is a programming error and is answered as one, because the only way to
 * reach a controller that asks for it is through the guard that binds it.
 */
final class ManagedBooking
{
    private ?BookingSnapshot $booking = null;

    private ?ManageToken $token = null;

    /**
     * @param  ManageToken|null  $token  the token the guard accepted, or null where a My bookings session admitted the request
     */
    public function bind(BookingSnapshot $booking, ?ManageToken $token): void
    {
        if ($this->booking !== null) {
            throw new LogicException(sprintf('A booking is already bound for this request: "%s".', $this->booking->uuid));
        }

        $this->booking = $booking;
        $this->token = $token;
    }

    public function release(): void
    {
        $this->booking = null;
        $this->token = null;
    }

    public function get(): BookingSnapshot
    {
        return $this->booking ?? throw new LogicException('No booking is bound: this route does not run behind the manage token guard.');
    }

    /**
     * The token this request presented and the guard accepted, or null where the request
     * presented none and a My bookings session admitted it: no plaintext exists on that road,
     * so there is none to hand on.
     */
    public function token(): ?ManageToken
    {
        $this->get();

        return $this->token;
    }
}
