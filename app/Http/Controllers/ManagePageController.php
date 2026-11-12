<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\BookingPage;
use App\Http\CustomerNotice;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /manage/{uuid} on the tenant subdomain, where the magic link lands. It renders the
 * booking page with the mount element in manage mode carrying the booking and the token, and
 * the booking app takes it from there.
 *
 * It verifies nothing. The API is the authority on a credential and this page is not a second
 * one: a page that refused a token it judged wrong would answer a question the API answers
 * already, differently the first day either changed, and it would be an existence oracle for
 * anyone walking uuids. A wrong token, a lapsed token and an unknown booking are one 404 on the
 * read the app then makes, and one neutral panel.
 *
 * So a link with no token renders too, with the token attribute absent rather than empty, and
 * the app shows that same neutral panel without asking the API anything.
 *
 * The URL carries a credential, so the answer is no-store, which keeps it out of every shared
 * cache and off the disk of a device the mail was read on, and no-referrer, which keeps the
 * token out of the Referer header of anything the page loads or links to. The session keeps no
 * previous URL for this route either (KeepLinkTokensOutOfTheSession), so the token reaches no
 * sessions row.
 *
 * The page's flash channel: the sentence the pay hop's redirect flashed, where one came, read
 * once, since the framework clears a flash after the page that reads it, and inlined beside the
 * config for the booking app to show above the booking. It is a sentence the hop wrote and
 * never holds the token.
 *
 * Public in the vocabulary of routes/tenant.php, and that is not a loophole: there is nothing
 * here to authorize. The page names a booking it has not read and says nothing about it.
 */
final class ManagePageController extends Controller
{
    public function __invoke(string $uuid, Request $request, CurrentTenant $current, BookingPage $page, CustomerNotice $notice): Response
    {
        $view = $page->view($current->get(), [
            BookingPage::MODE => BookingPage::MANAGE,
            BookingPage::UUID => $uuid,
            BookingPage::TOKEN => self::tokenOf($request),
        ], $notice->read());

        return new Response($view, Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * The token as the link spells it, or nothing. A parameter that is absent, repeated as an
     * array, or blank is no token: the attribute is then not written at all, which is what the
     * mount contract reads as null rather than as an empty credential to present.
     */
    private static function tokenOf(Request $request): ?string
    {
        $token = $request->query(TenantUrls::TOKEN);

        return is_string($token) && trim($token) !== '' ? $token : null;
    }
}
