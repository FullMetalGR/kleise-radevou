<?php

declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\Db\CustomerAccess;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cookie\Factory as CookieFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cookie a My bookings session rides in: host-only on the tenant's origin, Secure, HttpOnly
 * and SameSite Lax, said here rather than left to the session's configuration, and thirty days,
 * the session's own life.
 *
 * Its value is the session's secret, the plain value the row keeps the sha256 of, and the
 * framework encrypts it as it encrypts every cookie, so a value it did not write reads as none.
 * The secret is the session's name: a request presenting it is the session whose row holds its
 * hash. No script reads it, the page never prints it, and the booking app never sees it.
 */
final readonly class MyBookingsCookie
{
    public const string NAME = 'my_bookings';

    /** Minutes in the past a forgotten cookie is written with, the framework's own figure. */
    private const int FORGOTTEN = -2628000;

    public function __construct(private CookieFactory $cookies) {}

    /**
     * Gives the answer the cookie naming the session just opened.
     */
    public function set(Response $response, string $secret): Response
    {
        $response->headers->setCookie($this->cookies->make(self::NAME, $secret, self::lifetimeMinutes(), '/', null, true, true, false, 'lax'));

        return $response;
    }

    /**
     * Clears the cookie on the answer, with the attributes it was set with.
     */
    public function forget(Response $response): Response
    {
        $response->headers->setCookie($this->cookies->make(self::NAME, '', self::FORGOTTEN, '/', null, true, true, false, 'lax'));

        return $response;
    }

    /**
     * The secret a request presents, or null where it carries no cookie the framework wrote.
     */
    public static function read(Request $request): ?string
    {
        $secret = $request->cookie(self::NAME);

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * The session's thirty days in the minutes a cookie is made with.
     */
    public static function lifetimeMinutes(): int
    {
        $epoch = new DateTimeImmutable('@0', new DateTimeZone('UTC'));

        return intdiv($epoch->add(new DateInterval(CustomerAccess::SESSION_LIFETIME))->getTimestamp(), 60);
    }
}
