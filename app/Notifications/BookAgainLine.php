<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\BookingSnapshot;
use App\Http\LinkKind;
use App\Site\SiteLanguage;
use App\Tenancy\Languages;
use App\Tenancy\Slug;
use App\Tenancy\TenantUrls;

/**
 * Book again: the hosted page with the booking's first item preselected, its service and its staff
 * member, the staff left out where the item runs on the tenant's implicit member, the row standing
 * for the business itself, which no customer is shown as a person (ItemName's rule). One builder on
 * the server, over TenantUrls::bookingLink, for the three messages that carry it: the confirmation,
 * the reminder and the thank-you. The app's twin for My bookings and the manage page is
 * bookAgainLink in resources/js/booking/myBookings.ts, and the two spell one link,
 * {origin}/?service={id}&staff={id}, or under a language's prefix
 * {origin}/el?service={id}&staff={id}.
 *
 * It needs no switch and no credential: it points at the booking page, which is always there and
 * asks nobody who they are, so every form that carries it carries it whatever the tenant has
 * switched off, and it names no booking anybody could act on.
 *
 * The line is customer-mail.php's book_again in the booking's language, and the page it opens is
 * under that language's prefix, /en or /el, as the manage and payment links of the same messages
 * are, so the page speaks the language the message is written in; the constant that held the
 * sentence holds its key and keeps its name.
 */
final readonly class BookAgainLine
{
    /** The line, :link the hosted page with the preselection. */
    public const string LINE = 'customer-mail.book_again';

    /**
     * The link for a booking: its first item's service, and its staff member unless that is the
     * implicit one, under the prefix of the booking's language. The first item is the first in
     * chain order, which is how a snapshot holds them, and items[0] is always there:
     * BookingSnapshot refuses an empty list when it is built, so its items are a non-empty-list
     * past that guard and this read needs none of its own.
     */
    public static function for(TenantUrls $urls, Slug $slug, BookingSnapshot $booking): string
    {
        $first = $booking->items[0];
        $query = [LinkKind::Service->value => $first->serviceId];

        if (! $first->staffIsImplicit) {
            $query[LinkKind::Staff->value] = $first->staffId;
        }

        return $urls->bookingLink($slug, $query, Languages::ofBooking($booking->language));
    }

    /**
     * The line in the message's language, after a blank line.
     */
    public static function line(string $link, SiteLanguage $language): string
    {
        return "\n\n".CustomerMailLines::line(self::LINE, $language, ['link' => $link]);
    }
}
