<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\CustomerNotice;
use App\Http\PayHopAnswer;
use App\Http\PayPages;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\CurrentTenant;
use App\Tenancy\PayLinks;
use App\Tenancy\TenantPages;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The approved message's payment link, GET and POST /pay/{uuid}/link, behind its signature alone:
 * never the subscription's, since paying is a guest route.
 *
 * The GET is the status page and nothing else: it reads the booking as it stands, offers the
 * pay button while the payment hold is live, and decides nothing, so a mail scanner's fetch
 * writes no payment_ref, moves no hold and opens no session. The provider returns the customer
 * here, and so does the hop's redirect after a press, with the sentence it flashed, which the
 * page shows once (CustomerNotice). The POST is the hop, PayHopAnswer's on this road: it seals nothing, because nothing on
 * this road holds a plaintext token, and the customer comes back to the status page.
 *
 * Both re-mint the link from the booking rather than echo the address bar, as the decision pages
 * do, so what the button posts to and what the provider returns to are URLs PayLinks made, under
 * the language prefix the request came in at, none for the bare path.
 */
final class PayLinkController extends Controller
{
    public function show(Request $request, CurrentTenant $current, BookingReader $bookings, PaymentFactory $payments, PayLinks $links, PayPages $pages, CustomerNotice $notice): Response
    {
        $tenant = $current->get();
        $uuid = self::uuidOf($request);
        $booking = $bookings->snapshot($tenant, $uuid);

        if ($booking === null) {
            return $pages->refused(Response::HTTP_FORBIDDEN);
        }

        return $pages->status($tenant->name, $payments->hop($tenant)->standing($uuid), $links->link($tenant, $uuid, $booking->endsAt(), TenantPages::prefixOf($request->path())), $notice->read());
    }

    public function act(Request $request, CurrentTenant $current, BookingReader $bookings, PayLinks $links, PayPages $pages, PayHopAnswer $hop): Response
    {
        $tenant = $current->get();
        $booking = $bookings->snapshot($tenant, self::uuidOf($request));

        if ($booking === null) {
            return $pages->refused(Response::HTTP_FORBIDDEN);
        }

        return $hop->for($tenant, $booking, $links->link($tenant, $booking->uuid, $booking->endsAt(), TenantPages::prefixOf($request->path())), null);
    }

    private static function uuidOf(Request $request): string
    {
        $uuid = $request->route(PayLinks::UUID);

        return is_string($uuid) ? $uuid : '';
    }
}
