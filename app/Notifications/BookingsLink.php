<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\MyBookings\RequestBookingsLink;
use App\Application\Notification\Message;
use App\Application\Notification\MessageKey;
use App\Application\Notification\Recipient;
use App\Site\SiteLanguage;
use DateInterval;

/**
 * The one-time link a customer asked for on the hosted page: it opens My bookings, the session
 * over every booking of this address at this tenant, once and inside fifteen minutes.
 *
 * **It carries the link and nothing else.** No booking is named, because a link is mailed only to
 * an address with bookings here and saying which would be telling whoever reads the mailbox more
 * than the page will; no password, because there is none; and no second link, so the one in it is
 * the one to open. The words say what to do with a message nobody asked for, since anybody may
 * type anybody's address into the form.
 *
 * It is about no booking, so the message names none: the one key that takes a null identifier.
 *
 * Its words are customer-mail.php's my_bookings_link group, read in the language of the request
 * that asked for the link, since no booking's language binds a message about none: the My
 * bookings form's page, its prefix or the tenant default, carried to the job that sends it. How
 * long the link opens for is the request's own lifetime, RequestBookingsLink, spelled out in
 * words by ICU and read as a choice by it. The constants that held its sentences hold their keys
 * and keep their names.
 */
final readonly class BookingsLink
{
    /** The subject, :business the business whose bookings the link opens. */
    public const string SUBJECT = 'customer-mail.my_bookings_link.subject';

    /**
     * The body's own sentence after its greeting, naming the link set on the line after it,
     * :business the business; the greeting and how long the link opens for are named in place.
     */
    public const string BODY = 'customer-mail.my_bookings_link.here';

    public static function of(string $email, string $tenantName, string $link, SiteLanguage $language): Message
    {
        $body = implode("\n\n", [
            CustomerMailLines::line('customer-mail.my_bookings_link.greeting', $language),
            CustomerMailLines::line(self::BODY, $language, ['business' => $tenantName]),
            '    '.$link,
            CustomerMailLines::spelledChoice('customer-mail.my_bookings_link.once', self::minutes(), $language),
        ]);

        return new Message(
            MessageKey::MyBookingsLink,
            new Recipient($email),
            CustomerMailLines::line(self::SUBJECT, $language, ['business' => $tenantName]),
            $body,
            null,
            language: $language->value,
        );
    }

    /**
     * How many minutes the link opens for, the request's own lifetime read from the interval it
     * mints the link with, so a changed lifetime never needs a copy edit.
     */
    private static function minutes(): int
    {
        $lifetime = new DateInterval(RequestBookingsLink::LIFETIME);

        return $lifetime->d * 1440 + $lifetime->h * 60 + $lifetime->i;
    }
}
