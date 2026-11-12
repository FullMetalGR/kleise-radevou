<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Enum\BookingSource;
use Illuminate\Http\Request;

/**
 * Which surface a public hold came through, as the server reads it and no client says it: the
 * request's Origin header, or failing that the origin of its Referer, against the tenant's own
 * origin. Equal is the hosted page, which is served from that origin; anything else is the
 * embed, which runs on the tenant's own site, and so is a request carrying neither header,
 * since a hold this cannot place is a hold from somewhere other than the page we serve.
 *
 * An Origin header is present on every cross-origin fetch and on a same-origin POST in every
 * current browser, and a browser writes it lowercase; the Referer is the fallback for a client
 * that withholds it, read down to its scheme, host and port. Both sides are compared lowercase,
 * and an opaque origin, the literal null, is not the tenant's.
 */
final readonly class HoldSource
{
    public static function of(Request $request, string $tenantOrigin): BookingSource
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null) {
            $origin = self::originOf($request->headers->get('Referer'));
        }

        return $origin !== null && strtolower($origin) === strtolower($tenantOrigin)
            ? BookingSource::Hosted
            : BookingSource::Embed;
    }

    /**
     * A URL's origin, scheme, host and port where one is written, or null where the text has no
     * scheme and host to read.
     */
    private static function originOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
