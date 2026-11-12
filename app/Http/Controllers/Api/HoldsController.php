<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Actor;
use App\Http\ApiLines;
use App\Http\BookingPayload;
use App\Http\Controllers\Controller;
use App\Http\ErrorEnvelope;
use App\Http\HoldSource;
use App\Http\Requests\NewHoldRequest;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\CustomerProfiles;
use App\Infrastructure\Db\Pool;
use App\Infrastructure\HoldBookingFactory;
use App\Infrastructure\PaymentFactory;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/holds: the one public route that writes. The chain the customer described is
 * planned from the catalog, re-validated under lock and written whole or not at all, and the
 * answer carries the booking as the payload whitelist allows a guest to see it, with the manage
 * token beside it.
 *
 * The use case is built by the factory for the tenant the request resolved, which is the only
 * place a use case and its repositories are paired, so neither can belong to another tenant.
 * Every refusal is a typed exception the envelope answers by kind: a 409 is a normal answer here
 * and not an error, because the availability read is advisory and this is the authority.
 *
 * The answer is read back from the row that was just written rather than assembled from the
 * intention that wrote it, so a customer is shown what the tenant's staff will see. It is
 * no-store: it carries a credential that exists once, and nothing about a booking may sit in a
 * shared cache.
 *
 * The actor of the trail this writes is the customer: this route is the booking page, and a guest
 * has no account to reference. A hold made by hand in the admin carries the user who made it, on
 * a route of its own.
 *
 * The booking's source is read here, off the request's origin against the tenant's own,
 * HoldSource, and handed to the request with the body's channel and note, so the client names
 * none of it and the trail carries none of it.
 *
 * On the demo the visitor's name, address, phone and note are swapped for the sample customer's
 * here, once the body has passed validation and before anything is written: a refusal is the
 * visitor's own, and the booking holds the sample's. The guard reads the configured label and
 * nothing else, so every other tenant's hold is handed on as it came.
 *
 * An address the business blocked from booking online on the customer's page is refused before
 * the use case runs: a 403 whose code is blocked and whose detail is one sentence saying only
 * that booking online is not available, with nothing of the block, its reason or the profile in
 * the answer, and nothing written. The address is the one the customer typed, matched as citext
 * compares it, before the demo swaps it. The admin's wizard is another route and books the
 * address all the same.
 *
 * Public by design: no key and no token. Rate limited on its own, ten a minute per IP per tenant,
 * in place of the general limit and not beside it.
 */
final class HoldsController extends Controller
{
    /** The code of the refusal of a blocked address. */
    public const string BLOCKED = 'blocked';

    public function __invoke(NewHoldRequest $request, CurrentTenant $current, HoldBookingFactory $holds, BookingReader $bookings, PaymentFactory $payments, TenantUrls $urls, Demo $demo, Languages $languages, CustomerProfiles $profiles): JsonResponse
    {
        $tenant = $current->get();
        $source = HoldSource::of($request, $urls->origin(Slug::of($tenant->slug)));
        // The booking's language is the hold request's, its lang where the resolver reads it,
        // else the tenant default, and nothing updates it afterwards.
        $asked = $request->toHoldRequest($source, $languages->ofRequest($request, $tenant));

        // An address the business blocked from booking online is refused before anything is
        // planned or written, with the one sentence and nothing of the block.
        if ($profiles->blocked($tenant, $asked->customer->email)) {
            return (new ErrorEnvelope(self::BLOCKED, Response::HTTP_FORBIDDEN, ApiLines::line(ErrorEnvelope::BLOCKED_DETAIL)))->toResponse();
        }

        $held = $holds->for($tenant, Pool::Online)->hold($demo->sampled($tenant, $asked), Actor::customer());
        $booking = $bookings->snapshot($tenant, $held->uuid)
            ?? throw new LogicException(sprintf('The hold on tenant "%s" committed and its booking cannot be read back.', $tenant->slug));

        // Asked after the hold committed, of our own rows and the stored connection: the hold calls
        // no provider, and the session is opened by the hop on the customer's press. The hop is
        // under the prefix of the language the hold named, the booking's, and bare where it named
        // none, the booking's language then the tenant default the bare hop answers in; its way
        // back keeps the hop's prefix. An online hold the hop cannot take the money for says so,
        // for the done step to tell the customer they pay at the venue.
        $hop = $payments->hop($tenant);
        $checkoutUrl = $hop->offers($held->uuid)
            ? $urls->payLink(Slug::of($tenant->slug), $held->uuid, $held->manageToken, $languages->namedBy($request))
            : null;
        $paysAtVenue = $checkoutUrl === null && $hop->paysAtVenue($held->uuid);

        $response = new JsonResponse(BookingPayload::hold($booking, $held->manageToken, $checkoutUrl, $paysAtVenue), Response::HTTP_CREATED);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
