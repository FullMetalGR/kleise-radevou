<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Site\SignupLinks;
use App\Tenancy\TenantPages;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * The framework's session, less one habit on the routes whose URL is a credential.
 *
 * After every GET the framework writes the URL it served into the session as the previous URL,
 * for a redirect back to read. On GET /my-bookings/{token} that URL is the one-time link itself,
 * so the token would land in the session store, a sessions row in the clear, beside a session
 * that has nothing to go back to: the link's page posts to its own address and nothing reads the
 * slot (the token is stored by nothing). Its POST, the button that opens the session, is named
 * beside it: the framework writes no previous URL for a POST today, and the name keeps that from
 * resting on the framework's habit. The set-password link's page, GET
 * /admin/set-password/{token}, is the same, and so is its malformed sibling, whose path may be a
 * live link spelled in the wrong case; the page's refused form goes back to the page by its own
 * URL and never by this slot. The support session's link, GET /admin/support/{token}, and its
 * malformed sibling are the same again: the open redirects on and nothing goes back to it.
 *
 * The manage token travels in the query string of every GET behind its guard, so the manage page,
 * GET /manage/{uuid}, the pay hop, GET /pay/{uuid}, and the manage view's two reads, GET
 * /api/v1/bookings/{uuid} and its availability, are the same, and so is the payment link's status
 * page, GET /pay/{uuid}/link, whose signature is the credential: every request starts a session
 * and the database driver writes it, a row per request a browser makes without the session's
 * cookie, the booking app's reads among them. Nothing on those routes goes back to the previous
 * URL either.
 *
 * The signup's verification link, GET /signup/verify/{token}, is the same again, and so is its
 * malformed twin, whose path may be a live link spelled in the wrong case: the click builds the
 * business or answers the form with a refusal, and nothing goes back to the link.
 *
 * A page route's twins under /en and /el carry the same credential in the same place, so a name
 * is read through TenantPages::pageOf and the list names each page once.
 *
 * On the routes named here the URL is not written; on every other route the session behaves
 * exactly as the framework's does.
 */
final class KeepLinkTokensOutOfTheSession extends StartSession
{
    /** The routes whose URL carries a credential the session must not keep. */
    public const array ROUTES = [
        'my-bookings.open',
        'my-bookings.open.act',
        'admin.set-password',
        'admin.set-password.malformed',
        'admin.support.open',
        'admin.support.malformed',
        'manage',
        'pay',
        'pay.link',
        'api.booking',
        'api.booking.availability',
        SignupLinks::VERIFY,
        SignupLinks::VERIFY_MALFORMED,
    ];

    /**
     * @param  Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session): void
    {
        $name = $request->route()?->getName();

        if ($name !== null && in_array(TenantPages::pageOf($name), self::ROUTES, true)) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
