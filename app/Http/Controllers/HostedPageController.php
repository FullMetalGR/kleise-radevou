<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Clock;
use App\Http\BookingPage;
use App\Http\MyBookingsCookie;
use App\Infrastructure\Db\BookingReader;
use App\Infrastructure\Db\CustomerAccess;
use App\Site\Demo;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The hosted booking page: the first thing anyone can open at a tenant's subdomain.
 * Server-rendered, not Inertia; Inertia is the admin's transport and the public surface does
 * not use it. The page renders the tenant's name, carries the theme on the page chrome and on
 * the mount element, and inlines the boot config, so the bundle's first render needs no round
 * trip. It loads the one booking bundle by the same URL the embed snippet names, so the two
 * surfaces run the same script and the hosted page is its first tenant.
 *
 * The mount element names no mode and no credentials, which is what book mode is: anything but
 * the trimmed word manage means book. A link's query string, ?service=, ?staff= and ?ch=,
 * becomes the mount's data-service, data-staff and data-channel where each reads as the
 * client's own readers would keep it, BookingPage::linkMount, so a link from the admin's copy
 * buttons or a QR code preselects as an embed's attributes do.
 *
 * With a My bookings session open on the cookie, the inlined config carries the customer the
 * flow's details step starts from, the name, the email and the phone of the session's most
 * recent booking that is not cancelled, rejected or expired, and the answer is no-store. A
 * cookie naming no live session, or a session whose every booking came to nothing, inlines no
 * customer, and the page is today's.
 *
 * The demo inlines its sample customer in that key instead, so its details step starts filled
 * with the details every booking there is written under. No session is read on it: the demo's
 * link POST mints nothing, so none can be open. The answer is not no-store, since the sample is
 * nobody's and the page carries no person.
 *
 * Public by design: no key and no token on the read surface.
 */
final class HostedPageController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $current, BookingPage $page, CustomerAccess $access, BookingReader $bookings, Clock $clock, Demo $demo): Response
    {
        $tenant = $current->get();

        if ($demo->is($tenant)) {
            return new Response($page->business($tenant, $request->query(), $demo->sample())->render(), Response::HTTP_OK);
        }

        $secret = MyBookingsCookie::read($request);
        $email = $secret === null ? null : $access->sessionAddress($tenant, $secret, $clock->now());
        $customer = $email === null ? null : $bookings->latestCustomerOf($tenant, $email);
        $view = $page->business($tenant, $request->query(), $customer)->render();

        // A page carrying a customer's name, address and phone is kept out of every cache.
        return new Response($view, Response::HTTP_OK, $customer === null ? [] : ['Cache-Control' => 'no-store']);
    }
}
