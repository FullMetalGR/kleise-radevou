<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ManagedBooking;
use App\Http\PayHopAnswer;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Slug;
use App\Tenancy\TenantPages;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pay hop under the manage token, GET /pay/{uuid}, the booking's checkout_url: behind the
 * token's one guard, never the subscription's. What it answers is PayHopAnswer's; the provider
 * brings the customer back to the manage page with the token, paid or not, and that page reads
 * the booking as it stands.
 *
 * A request the guard admitted on a My bookings session presented no token: there is no
 * plaintext to seal and no manage link to spell, so the way back is My bookings, which reads the
 * booking under the same session, and the hop is handed no token, as the signed road hands it
 * none. The confirmation that follows a payment then says where the customer's link is, as it
 * does wherever no token was seen.
 *
 * The way back keeps the prefix the hop was asked under, bare for none, so the redirect and the
 * provider's return land on the manage page or My bookings in the hop's language; the
 * checkout_url a hold answers names the hop under the prefix of the language the hold named,
 * bare where it named none.
 */
final class PayController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $current, ManagedBooking $managed, TenantUrls $urls, PayHopAnswer $hop): Response
    {
        $tenant = $current->get();
        $booking = $managed->get();
        $token = $managed->token()?->plain;
        $slug = Slug::of($tenant->slug);
        $prefix = TenantPages::prefixOf($request->path());
        $back = $token === null ? $urls->myBookings($slug, $prefix) : $urls->manageLink($slug, $booking->uuid, $token, $prefix);

        return $hop->for($tenant, $booking, $back, $token);
    }
}
