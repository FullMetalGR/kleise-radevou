<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingSnapshot;
use App\Infrastructure\Db\BookingReader;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use LogicException;

/**
 * What a guest route answers once a guarded transition has committed: the booking as it now
 * stands, in the guest view.
 *
 * It is read back from the row the transition left rather than assembled from what the
 * transition meant to write, so the customer is shown what the tenant's staff will see. The two
 * would agree today and the one that is true is the row: a transition describes a status and
 * two columns, and everything else on the answer, the items, the total and the names, is read
 * from the booking either way.
 *
 * The answer is no-store, because it names a person. Nothing about a booking may sit in a
 * shared cache, and a manage link is opened on whatever device the mail was read on.
 *
 * A booking that cannot be read back after its own transition committed is a programming error
 * and is raised as one rather than answered as a 404: the row was locked and written moments
 * earlier in this same request, so the only ways here are a reader pointed at another tenant or
 * a transaction that did not commit what it said it did. The transition is named in the message
 * so the log says which one, and no identifier reaches the customer, who gets the fixed 500
 * sentence of the envelope.
 */
final readonly class BookingAnswer
{
    public function __construct(private BookingReader $bookings) {}

    /**
     * @param  string  $transition  what committed, for the message if the row cannot be read back
     */
    public function readBack(Tenant $tenant, string $uuid, string $transition): JsonResponse
    {
        $booking = $this->bookings->snapshot($tenant, $uuid) ?? throw new LogicException(sprintf(
            'The %s on tenant "%s" committed and its booking cannot be read back.',
            $transition,
            $tenant->slug,
        ));

        return $this->of($booking);
    }

    /**
     * The guest view of a booking already in hand, for the read that has one: the same shape
     * and the same header, decided here rather than beside each route.
     */
    public function of(BookingSnapshot $booking): JsonResponse
    {
        $response = new JsonResponse(BookingPayload::guest($booking));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
