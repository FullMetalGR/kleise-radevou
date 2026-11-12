<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Notification\MessageKey;
use App\Application\Notification\Messenger;
use App\Site\SiteLanguage;
use App\Tenancy\Slug;
use App\Tenancy\Tenant;
use App\Tenancy\TenantUrls;

/**
 * The closing line of every customer message: the tenant's My bookings page, the way to every
 * booking of the address the message went to.
 *
 * It points at a page that sends the bookings link, so it is there exactly while that key is on,
 * the seam's own answer read at the send as every job reads its switches, and absent while it is
 * off, when the page could only say no link is coming. Business messages carry none: the business
 * has the admin.
 *
 * The line is customer-mail.php's my_bookings in the message's language, the booking's, and the
 * page is under that language's prefix, /en or /el, as the manage and payment links of the same
 * messages are, so the page speaks the language the message is written in; the constant that held
 * the sentence holds its key and keeps its name.
 */
final readonly class MyBookingsLine
{
    /** The line, :link the tenant's My bookings page. */
    public const string LINE = 'customer-mail.my_bookings';

    /**
     * The page a customer message closes on, under the prefix of the language given, the
     * booking's, or null where the tenant has the bookings link off.
     */
    public static function for(Messenger $messenger, TenantUrls $urls, Tenant $tenant, SiteLanguage $language): ?string
    {
        return $messenger->sends(MessageKey::MyBookingsLink) ? $urls->myBookings(Slug::of($tenant->slug), $language) : null;
    }

    /**
     * The line in the message's language, after a blank line, or nothing where there is no page to
     * point at.
     */
    public static function closing(?string $page, SiteLanguage $language): string
    {
        return $page === null ? '' : "\n\n".CustomerMailLines::line(self::LINE, $language, ['link' => $page]);
    }
}
