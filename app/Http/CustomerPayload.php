<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\CustomerProfile;
use App\Application\Dto\CustomerRecord;

/**
 * What a viewer of the admin may see of a customer: the row of the Customers list and the head of
 * a customer's page. A customer is read from the bookings' customer columns, so the CONTACT rule
 * of BookingPayload decides it as it decides a booking: the ways to reach the customer, the email
 * and the phones seen, reach only a viewer holding manageBookings, and a key withheld is absent,
 * never null. Every key read from a customer column is named here beside the column, and a key is
 * withheld exactly where its column is in BookingPayload::CONTACT, so the one list decides both
 * surfaces.
 *
 * url is the customer's page, GET /admin/customers/{email}, the address as the most recent
 * booking stored it, percent-encoded as one path segment. The address therefore rides in every
 * link, which is why both pages sit under manageBookings, the permission the CONTACT rule reads:
 * no viewer the email is withheld from reaches a row that links it.
 *
 * The visits are instants in the API's spelling, RFC 3339 in UTC, converted to the tenant's zone
 * on the screen; the last visit is null where the address has none passed. The four counts of the
 * page's tiles, upcoming, completed, no_shows and cancelled, are counts of bookings and read no
 * customer column, so they reach every viewer the payload reaches.
 *
 * Beside them the address's profile as the list's name cell and the page's head draw it: its
 * tags, each once in the order written, and whether online booking is blocked for it; an address
 * with no profile row has no tags and is not blocked. The profile is keyed by the address, not
 * read from a customer column, and both pages sit under manageBookings, so it reaches every
 * viewer the payload reaches.
 */
final readonly class CustomerPayload
{
    /**
     * The keys of the payload read from a customer column of bookings, each with its column.
     */
    public const array COLUMNS = ['name' => 'customer_name', 'names' => 'customer_name', 'email' => 'customer_email', 'phones' => 'customer_phone'];

    /**
     * @return array<string, mixed>
     */
    public static function admin(CustomerRecord $customer, bool $manageBookings, CustomerProfile $profile): array
    {
        $payload = [
            'name' => $customer->names[0],
            'names' => $customer->names,
            'email' => $customer->email,
            'phones' => $customer->phones,
            'first_visit' => BookingPayload::instant($customer->firstVisit),
            'last_visit' => $customer->lastVisit === null ? null : BookingPayload::instant($customer->lastVisit),
            'bookings' => $customer->bookings,
            'upcoming' => $customer->upcoming,
            'completed' => $customer->completed,
            'no_shows' => $customer->noShows,
            'cancelled' => $customer->cancelled,
            'url' => self::url($customer->email),
            'tags' => $profile->tags,
            'blocked' => $profile->blocked,
        ];

        return $manageBookings ? $payload : array_diff_key($payload, array_intersect(self::COLUMNS, BookingPayload::CONTACT));
    }

    /**
     * The customer's page for an address, as one path segment.
     */
    public static function url(string $email): string
    {
        return AdminSession::PREFIX.'/customers/'.rawurlencode($email);
    }
}
