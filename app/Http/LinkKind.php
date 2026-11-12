<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What a booking link preselects: a service or a staff member, each the query key the hosted page
 * reads, ?service= or ?staff=, and the path segment of its QR route, GET
 * /admin/settings/links/{kind}/{id}.svg. The page's own link preselects nothing and is no kind.
 *
 * The one spelling of the two keys on the server: the hosted page's mount reads them here, and the
 * business page's offers, Book again's link and the Links card write them from here. The booking
 * app spells them once, SERVICE_QUERY and STAFF_QUERY in resources/js/booking/myBookings.ts, held
 * to these values by SharedHomesTest.
 */
enum LinkKind: string
{
    case Service = 'service';
    case Staff = 'staff';
}
