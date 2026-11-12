<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Clock;
use App\Http\PayPages;
use App\Tenancy\PayLinks;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization check of the approved message's payment link, and the declaration that
 * names it: the signature over the booking and the link's outer life, run before the page or
 * the act does anything at all.
 *
 * **The signature covers the path and the expiry, and nothing else that arrives.** The provider
 * brings the customer back to this same address with parameters of its own, a session id on
 * the fake and on Stripe, so every query parameter but the expiry is left out of the check:
 * what may be appended cannot widen what the link grants, because the link grants one page
 * about one booking whatever is appended, and what is signed cannot be edited.
 *
 * **The expiry is read where the signature reads it**: the framework builds the signed text
 * from the raw query string and keeps the one pair whose raw name is exactly the expiry's,
 * while the decoded query bag turns "%65xpires", "expire%73", "+expires" and "%20expires" into
 * the same name with the last value winning. A later instant appended under such a spelling is
 * left out of the signed text and would be the one the clock was shown. So the instant compared
 * is the raw pair's, the one the signature covers, there must be exactly one, and a decoded
 * expiry that disagrees with it is refused as a link nothing minted.
 *
 * **The outer life is compared against the application's clock**, as the decision links' is,
 * and past it the link is refused rather than answered: it is a credential's own lifetime,
 * thirty days after the booking's last segment, not the hold's. Whether the hold is live is the
 * row's question and the page's, asked of the database's now() every time.
 *
 * **Every refusal is one page**, the pay hop's worded one at the decision links' status, 403:
 * a signature that does not verify, a link past its life and an expiry of the wrong shape
 * answer alike and name nothing, so a stale or forged link learns nothing about the booking.
 */
final readonly class RequiresPayLink
{
    public const string NAME = 'pay-link';

    public function __construct(
        private UrlGenerator $urls,
        private Clock $clock,
        private PayPages $pages,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expires = self::signedExpiry($request);

        if ($expires === null) {
            return $this->pages->refused(Response::HTTP_FORBIDDEN);
        }

        $signed = $this->urls->hasCorrectSignature($request, true, fn (string $parameter): bool => $parameter !== PayLinks::EXPIRES);

        if (! $signed || $this->clock->now()->getTimestamp() >= $expires) {
            return $this->pages->refused(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * The expiry the signed text carries, or null: the value of the one pair of the raw query
     * string named exactly as the expiry, read from the server variable the framework's check
     * reads, a whole number, and the same value the decoded query holds under that name. Its
     * shape is asked before the signature is, as the decision links ask theirs, so no malformed
     * value reaches the comparison.
     */
    private static function signedExpiry(Request $request): ?int
    {
        $raw = $request->server->get('VAPOR_RAW_QUERY_STRING') ?? $request->server->get('QUERY_STRING');
        $values = [];

        foreach (explode('&', is_string($raw) ? $raw : '') as $pair) {
            $parts = explode('=', $pair, 2);

            if ($parts[0] === PayLinks::EXPIRES) {
                $values[] = $parts[1] ?? '';
            }
        }

        if (count($values) !== 1 || preg_match('/^[0-9]+$/D', $values[0]) !== 1 || $request->query(PayLinks::EXPIRES) !== $values[0]) {
            return null;
        }

        return (int) $values[0];
    }
}
