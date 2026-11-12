<?php

declare(strict_types=1);

namespace App\Http;

use App\Tenancy\Slug;
use App\Tenancy\TenantProvisioner;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cookie\Factory as CookieFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser that holds a signup: the signup POST answers with one cookie naming the slug as
 * stored, and the pending page names the business and the address the link went to only to a
 * request carrying it for that slug. The framework encrypts it as it encrypts every cookie, so a
 * value it did not write reads as none. It is host-only on the central host, the session's domain
 * being unset, and lives the hold's 24 hours. A browser that starts a second signup for another
 * slug overwrites it: one cookie, one slug.
 */
final readonly class SignupHolder
{
    public const string COOKIE = 'signup_holder';

    public function __construct(private CookieFactory $cookies) {}

    /**
     * Marks the answer that sends this browser to the slug's pending page as the slug's holder.
     */
    public function mark(Response $response, Slug $slug): Response
    {
        $response->headers->setCookie($this->cookies->make(self::COOKIE, $slug->value, self::lifetimeMinutes()));

        return $response;
    }

    /**
     * Whether the request comes from the browser that holds the slug's signup.
     */
    public static function holds(Request $request, Slug $slug): bool
    {
        return $request->cookie(self::COOKIE) === $slug->value;
    }

    /**
     * The hold's 24 hours in the minutes a cookie is made with.
     */
    private static function lifetimeMinutes(): int
    {
        $epoch = new DateTimeImmutable('@0', new DateTimeZone('UTC'));

        return intdiv($epoch->add(new DateInterval(TenantProvisioner::ABANDONED_AFTER))->getTimestamp(), 60);
    }
}
