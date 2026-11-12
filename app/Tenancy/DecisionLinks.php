<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Enum\Decision;
use App\Site\SiteLanguage;
use DateTimeImmutable;
use Illuminate\Routing\UrlGenerator;

/**
 * Where a decision link is minted: a signed URL on the tenant's own subdomain carrying the
 * booking, the decision, the user it was minted for and an expiry that is the hold's.
 *
 * **One link per user, not one per booking.** The decision has to land as somebody, so the
 * audit row and approved_by can say who, and so a link forwarded to a colleague still decides
 * as the person it was sent to rather than as whoever happened to open it. Two approvers
 * therefore receive two different links to the same booking, and the message that carries them
 * is built per recipient for exactly that reason.
 *
 * **Everything the page will act on is inside the signature.** The uuid and the decision are
 * path segments and the user and the expiry are query parameters, and all four are hashed with
 * the absolute URL, the host included. So a reject link cannot be edited into an approve link,
 * a link for one booking cannot be pointed at another, nobody can promote themselves by editing
 * a number, and a link minted for one tenant does not verify on another's subdomain although
 * both run on one key.
 *
 * **The expiry is the approval window's**, and it is the hold's own expiry read from the row
 * rather than a lifetime of this class's own: the link dies exactly when the request it decides
 * does. Whether it has lapsed is compared against the application's clock where the page
 * renders, not here.
 *
 * It is one class with one method so that the approval request and the nags mint the same link
 * the same way. The route it signs is the page's, and the page posts back to a link minted here
 * again rather than to a reconstruction of its own address bar, so what is verified on the POST
 * is a URL this class produced. A link is minted at the bare path or under a language's prefix,
 * the page's twin, and the signature covers the path, so the page re-mints under the prefix it
 * was opened at.
 *
 * **It sits where a tenant's URLs are spelled**, beside TenantUrls and the magic link, rather
 * than in the HTTP layer: the two callers are a queued job, which runs with no request at all,
 * and the page that re-mints what the form posts to. A job reaching into the web to build a
 * link would be the queue depending on the request it does not have. The route's name is a
 * string in this direction, the way TenantUrls spells the manage path here and lets the guard
 * that reads it stay an HTTP concern; a test holds the route to it.
 */
final readonly class DecisionLinks
{
    /**
     * The page a link opens. The POST that decides is registered on the same URI, so a link
     * signed for the page is the link the form posts to, and the signature covers both without
     * either route knowing about the other.
     */
    public const string ROUTE = 'decision';

    /**
     * What the four parts of a link are called: two path segments naming the booking and the
     * decision, and two query parameters naming the user it decides as and the moment it stops
     * working. They are spelled here, where a link is made, and read from here by the guard
     * that takes one apart; the route file spells the two path segments again because a URI
     * pattern is a literal.
     */
    public const string UUID = 'uuid';

    public const string DECISION = 'decision';

    public const string USER = 'user';

    public const string EXPIRES = 'expires';

    public function __construct(private UrlGenerator $urls) {}

    /**
     * @param  SiteLanguage|null  $prefix  the language prefix the link's page is under, none for the bare path
     */
    public function link(Tenant $tenant, string $uuid, Decision $decision, int $userId, DateTimeImmutable $expiry, ?SiteLanguage $prefix = null): string
    {
        return $this->urls->signedRoute(
            TenantPages::routeName(self::ROUTE, $prefix),
            [
                // The tenant names the subdomain the route lives on. It is passed rather than
                // inherited from the current request, because a link is as often minted inside
                // a queued job, where there is no request and the only tenant known is the one
                // the job carries.
                'tenant' => $tenant->slug,
                self::UUID => $uuid,
                self::DECISION => $decision->value,
                self::USER => $userId,
            ],
            $expiry,
        );
    }
}
