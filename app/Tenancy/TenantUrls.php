<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Site\SiteLanguage;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * Where a tenant lives on the web. Every tenant surface sits on one subdomain of the central
 * host: the admin, the API, the hosted booking page and the embed script. The central host is
 * the application URL's, so development serves acme.localhost:8000 and production
 * acme.kleiseradevou.gr from the one setting that already names the deployment.
 */
final readonly class TenantUrls
{
    /**
     * What the manage token is called in the link's query string. Spelled here rather than
     * imported from the guard that reads it: this is the tenancy layer and the guard is an
     * HTTP concern, and one string in one direction is cheaper than the other way round.
     */
    public const string TOKEN = 'token';

    private string $scheme;

    private string $host;

    private ?int $port;

    public function __construct(#[Config('app.url')] string $appUrl)
    {
        $parts = parse_url($appUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidArgumentException(sprintf('The application URL must carry a host; "%s" does not.', $appUrl));
        }

        $this->scheme = $parts['scheme'] ?? 'http';
        $this->host = $parts['host'];
        $this->port = $parts['port'] ?? null;
    }

    /**
     * The host tenants are subdomains of. What the tenant resolver strips to find the slug.
     */
    public function centralHost(): string
    {
        return $this->host;
    }

    /**
     * The label a request's host names a tenant by: whatever sits directly to the left of the
     * central host, lowercased, with any port stripped. Null when the host is the central host
     * itself, sits under some other domain, or has more than one label in front, none of which
     * names a tenant.
     */
    public function labelOf(string $host): ?string
    {
        $host = strtolower(preg_replace('/:\d+\z/', '', $host) ?? $host);
        $suffix = '.'.strtolower($this->host);

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return $label;
    }

    /**
     * A path on the central host, where signup lives: the application URL's own origin.
     */
    public function central(string $path): string
    {
        return $this->originOf($this->host).$path;
    }

    /**
     * The platform console's host: the console label under the central host. The label is
     * reserved, so no tenant holds it, and the routes file registers the console's group on
     * this host before the tenant group.
     */
    public function consoleHost(): string
    {
        return 'console.'.$this->host;
    }

    /**
     * A path on the console's host, with the scheme and the port the application URL carries,
     * so development serves console.localhost:8000 and production console.kleiseradevou.gr.
     */
    public function console(string $path): string
    {
        return $this->originOf($this->consoleHost()).$path;
    }

    /**
     * Where a tenant's people sign in: the admin's door on the tenant's own origin. The
     * signup's pending page sends the new owner here once the row is active.
     */
    public function signIn(Slug $slug): string
    {
        return $this->origin($slug).'/admin/login';
    }

    /**
     * The set-password link the console mails a user: the page on the tenant's own origin, under
     * the admin prefix, where the user sets their own password. The token is a path segment,
     * escaped so it can never become two, and a secret like the My bookings link's, so the link is
     * logged nowhere.
     */
    public function setPasswordLink(Slug $slug, string $token): string
    {
        return $this->origin($slug).'/admin/set-password/'.rawurlencode($token);
    }

    /**
     * The support session's link: the open on the tenant's own origin, under the admin prefix,
     * where the operator's browser lands after the console's press. The token is a path segment,
     * escaped so it can never become two, and a secret answered once to the press, so the link
     * is logged nowhere.
     */
    public function supportLink(Slug $slug, string $token): string
    {
        return $this->origin($slug).'/admin/support/'.rawurlencode($token);
    }

    /**
     * The Billing screen, on the tenant's own origin: where the platform's own messages about a
     * trial and its close send the owner to choose a plan.
     */
    public function billing(Slug $slug): string
    {
        return $this->origin($slug).'/admin/billing';
    }

    public function origin(Slug $slug): string
    {
        return $this->originOf($slug->value.'.'.$this->host);
    }

    /**
     * The business page, at the tenant's root, or under a language's prefix where one is named,
     * /en or /el with no trailing slash. Every page link here takes the language the same way and
     * is bare where none is named.
     */
    public function hostedPage(Slug $slug, ?SiteLanguage $language = null): string
    {
        return $this->page($slug, '/', $language);
    }

    /**
     * A link to the hosted page that preselects or tags: the page with service, staff or ch in
     * its query string, which the page writes onto the mount. Spelled here and nowhere else, so
     * the copy buttons, the Links card, the QR codes and the business page's structured data
     * hand out one link and the page reads that one. With no query it is the hosted page itself.
     *
     * @param  array<string, int|string>  $query
     */
    public function bookingLink(Slug $slug, array $query = [], ?SiteLanguage $language = null): string
    {
        return $this->hostedPage($slug, $language).($query === [] ? '' : '?'.http_build_query($query));
    }

    public function embedScript(Slug $slug): string
    {
        return $this->origin($slug).'/embed.js';
    }

    /**
     * The magic link: where a customer manages the booking they hold the one credential for.
     * It is spelled here and nowhere else, so the email that carries it and the route that
     * answers it cannot drift apart, and a test holds the route to this.
     *
     * The token rides in the query string because the link has no body to put it in, and it is
     * optional here because the same URL without it is the page a customer reaches from a
     * truncated link, which renders and asks the API nothing. Both halves are escaped: the
     * identifier is a path segment that must not be able to become two, and the token is a
     * secret whose characters must not be able to become a second parameter.
     */
    public function manageLink(Slug $slug, string $uuid, ?string $token = null, ?SiteLanguage $language = null): string
    {
        $link = $this->page($slug, '/manage/'.rawurlencode($uuid), $language);

        return $token === null ? $link : $link.'?'.http_build_query([self::TOKEN => $token]);
    }

    /**
     * My bookings: the page listing every booking of the address a session was opened for, or
     * the form asking for a link where none is open. The hosted page's header links here, and
     * so does the closing line of every customer message.
     */
    public function myBookings(Slug $slug, ?SiteLanguage $language = null): string
    {
        return $this->page($slug, '/my-bookings', $language);
    }

    /**
     * The one-time link a My bookings request mails, which opens the session once inside its
     * fifteen minutes and goes on to the page. The token is a path segment, escaped so it can
     * never become two, and a secret like the manage token, so the link is logged nowhere.
     */
    public function myBookingsLink(Slug $slug, string $token, ?SiteLanguage $language = null): string
    {
        return $this->myBookings($slug, $language).'/'.rawurlencode($token);
    }

    /**
     * The pay hop, the checkout_url: a URL of ours on the tenant's host, carrying the booking
     * and its manage token, which is why it is never logged.
     */
    public function payLink(Slug $slug, string $uuid, string $token, ?SiteLanguage $language = null): string
    {
        return $this->page($slug, '/pay/'.rawurlencode($uuid), $language).'?'.http_build_query([self::TOKEN => $token]);
    }

    /**
     * The two lines a tenant pastes into their own site. The script's URL names the tenant, so
     * there is no tenant attribute and no key to get wrong.
     *
     * The theme rides on the mount element as data-theme, the word the hosted page writes on its
     * body and its mount, and the embed's stylesheet keys its colours on it. The embed never
     * fetches the theme, so the snippet is the one place a tenant gets it from, and the admin
     * generates it here rather than spelling it a second time.
     */
    public function embedSnippet(Slug $slug, Theme $theme = Theme::DEFAULT): string
    {
        return sprintf(
            "<div class=\"kr-booking\" data-theme=\"%s\"></div>\n<script src=\"%s\" async></script>",
            $theme->value,
            $this->embedScript($slug),
        );
    }

    /**
     * Where the hosted page loads the tenant's logo from: the tenant's own origin, so the page and
     * its image share one host whatever the public disk's URL is built from. The file name carries
     * a content hash, so a replaced logo is a new URL and no browser cache serves the old one; the
     * route answers 404 for any name but the current one.
     */
    public function logo(Slug $slug, string $file): string
    {
        return $this->origin($slug).'/logo/'.rawurlencode($file);
    }

    /**
     * Where a page loads one of the tenant's other stored images from, the cover or a gallery
     * photo: the tenant's own origin on GET /images/{file}, for the reason logo() gives. The
     * route answers 404 for any name no current column holds.
     */
    public function image(Slug $slug, string $file): string
    {
        return $this->origin($slug).'/images/'.rawurlencode($file);
    }

    /**
     * A page of the tenant's host at its bare path, or under the prefix of the language named.
     */
    private function page(Slug $slug, string $path, ?SiteLanguage $language): string
    {
        return $this->origin($slug).TenantPages::uri($path, $language);
    }

    /**
     * An origin on the given host with the application URL's scheme and port.
     */
    private function originOf(string $host): string
    {
        return sprintf('%s://%s%s', $this->scheme, $host, $this->port === null ? '' : ':'.$this->port);
    }
}
