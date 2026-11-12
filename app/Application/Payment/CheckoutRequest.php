<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Dto\BookingSnapshot;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * What the pay hop hands the port when it asks where to send a customer.
 *
 * The booking is its snapshot, so the total the provider is asked for is the booking's own,
 * fixed at hold time. paymentRef is the session already opened for the booking, null on the
 * first visit: given one, the provider answers that session again and creates nothing. The
 * return URL is the manage page carrying the manage token, which is why it is sensitive and
 * goes in no log, trace or payload. endsBy is the end the session is asked to have, the hold's
 * own expiry, because a hold and its session end together.
 *
 * sessionsCleared is how many sessions moves have cleared from the booking before this request.
 * At zero the hold still stands at its own term and the provider may end the session past it,
 * by its shortest session at most, the hold following; above zero the hold's end is already a
 * session's end and the provider ends this session with the hold as it stands, lengthening it
 * no further, and counts the number into its idempotency key, so a hold outlives its term by
 * the floor once whatever the number of moves and hops, and no hop meets a session a move
 * expired.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public BookingSnapshot $booking,
        public ?string $paymentRef,
        #[SensitiveParameter] public string $returnUrl,
        public DateTimeImmutable $endsBy,
        public int $sessionsCleared = 0,
    ) {}
}
