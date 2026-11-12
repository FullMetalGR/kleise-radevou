<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Enum\Decision;
use App\Http\DecisionLinkGuard;
use App\Http\DecisionPages;
use App\Http\PresentedLink;
use App\Tenancy\CurrentTenant;
use App\Tenancy\DecisionLinks;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization check of a decision link, and the declaration that names it: the signature
 * plus the three checks, run before either page does anything at all. Declaring it and
 * enforcing it are the same act here, because a label that only says what should have happened
 * is not a check.
 *
 * **The signature is the whole authentication.** Nothing here reads an admin session: what
 * arrives is a link the deployment minted for one booking, one decision and one user, and
 * holding it is the credential. The admin's bookings screen calls the same two use cases under
 * its own session, and these pages stay as they are.
 *
 * **The expiry is parsed here and compared nowhere near here.** A link past its window is not a
 * refusal: a replayed or late link answers with the outcome page, because from the owner's side
 * pressing a link twice is not a mistake worth a stack trace. So this admits it and hands the
 * instant on, and the page decides what to say. What this does refuse is a link carrying no
 * expiry at all, which nothing in this deployment mints.
 *
 * **Every refusal is one page.** A signature that does not verify, a user who is not of this
 * tenant, a role that may not approve and a staff user with nothing on this booking are one
 * answer with one body, and that body names no booking, no customer and no decision. A refusal
 * that varied would be an oracle: it would tell whoever holds a stale or forged link whether
 * the booking exists, who the tenant's people are, and which fact changed under them.
 */
final readonly class RequiresDecisionLink
{
    public const string NAME = 'decision-link';

    public function __construct(
        private UrlGenerator $urls,
        private DecisionLinkGuard $guard,
        private CurrentTenant $current,
        private PresentedLink $presented,
        private DecisionPages $pages,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $decision = self::decisionOf($request);
        $user = self::numberIn($request, DecisionLinks::USER);
        $expires = self::numberIn($request, DecisionLinks::EXPIRES);

        // The signature is asked for correctness alone and never for freshness. Both are in it,
        // but they are different answers: a wrong signature is somebody else's link and a stale
        // one is this owner's, a day late.
        if ($decision === null || $user === null || $expires === null || ! $this->urls->hasCorrectSignature($request)) {
            return $this->pages->refused();
        }

        $signed = $this->guard->verified(
            $this->current->get(),
            self::uuidOf($request),
            $decision,
            $user,
            self::instant($expires),
        );

        if ($signed === null) {
            return $this->pages->refused();
        }

        $this->presented->bind($signed);

        try {
            return $next($request);
        } finally {
            $this->presented->release();
        }
    }

    /**
     * The booking the path names. A route registered without the parameter would name no
     * booking, which the guard answers as a refusal rather than a crash.
     */
    private static function uuidOf(Request $request): string
    {
        $uuid = $request->route(DecisionLinks::UUID);

        return is_string($uuid) ? $uuid : '';
    }

    /**
     * The decision the path spells, or null where it spells neither. The route constrains the
     * segment already, so this is the second half of the same statement rather than a rule of
     * its own: a path that reached here naming something else is not a link this deployment
     * minted, whatever its signature says.
     */
    private static function decisionOf(Request $request): ?Decision
    {
        $decision = $request->route(DecisionLinks::DECISION);

        return is_string($decision) ? Decision::tryFrom($decision) : null;
    }

    /**
     * A query parameter that must be a whole number, or null. The signature covers both of the
     * ones read here, so a value of the wrong shape is a link nothing minted rather than one
     * somebody edited; it is refused on its shape before the signature is asked, so that no
     * malformed value reaches the comparison.
     */
    private static function numberIn(Request $request, string $name): ?int
    {
        $value = $request->query($name);

        return is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1 ? (int) $value : null;
    }

    /**
     * The expiry as an instant. A unix timestamp names one moment and no zone, and this names
     * UTC for it rather than letting the parse fall back to the process default, the zone
     * rule holding on the way in as much as on the way out.
     */
    private static function instant(int $timestamp): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');

        return (new DateTimeImmutable('@'.$timestamp, $utc))->setTimezone($utc);
    }
}
