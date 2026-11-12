<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\ApprovalWindows;
use App\Http\DecisionPages;
use App\Http\PresentedLink;
use App\Infrastructure\Db\BookingReader;
use App\Tenancy\CurrentTenant;
use App\Tenancy\DecisionLinks;
use App\Tenancy\TenantPages;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /decide/{uuid}/{decision} on the tenant subdomain, where a signed decision link lands.
 *
 * **It decides nothing, however many times it is fetched.** No status, no audit row, no event.
 * That is not politeness: mail security scanners follow every link in a message before it
 * reaches the inbox, so a link that decided on GET would have every approval decided by a
 * robot, silently, before the owner read the email. What this renders is a page with one
 * button, and the POST behind that button is the whole of the decision.
 *
 * The authorization ran in the middleware, which is the route's declared check: the signature,
 * and the three facts that a signature cannot prove. What is left here is which of two pages to
 * render, and that is the state of the booking rather than a question about who is asking.
 *
 * A link for a booking this tenant does not have is a 404. It cannot be reached by anything
 * this deployment minted, since a link is signed against the booking it was minted for, and
 * there is no outcome to render for a booking that is not there.
 */
final class DecisionPageController extends Controller
{
    public function __invoke(
        Request $request,
        PresentedLink $presented,
        CurrentTenant $current,
        BookingReader $bookings,
        ApprovalWindows $windows,
        DecisionLinks $links,
        DecisionPages $pages,
    ): Response {
        $tenant = $current->get();
        $link = $presented->get();
        $booking = $bookings->snapshot($tenant, $link->uuid);

        if ($booking === null) {
            throw new NotFoundHttpException;
        }

        $window = $windows->of($tenant, $booking, $link);

        if (! $window->isOpen) {
            return $pages->outcome($tenant, $booking, $link, $window);
        }

        return $pages->confirm(
            $tenant,
            $booking,
            $link,
            // The form posts to a link minted again, which is the same URL this request
            // arrived at because both come from one place, under the language prefix it
            // arrived under, none for the bare path. Re-minting rather than echoing the address
            // back means what the POST presents is a URL the signature was computed over,
            // whatever a proxy did to the one in the address bar.
            $links->link($tenant, $link->uuid, $link->decision, $link->userId, $link->expiresAt, TenantPages::prefixOf($request->path())),
        );
    }
}
