<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Dto\BookingSnapshot;
use App\Infrastructure\Db\SettingsReader;
use App\Notifications\DecisionLines;
use App\Notifications\TenantClock;
use App\Site\SiteLanguage;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;

/**
 * The three answers a decision link can get, built in one place: the confirm page, the outcome
 * page, and the refusal.
 *
 * They are one class because they share what makes them safe to send. Each carries a credential
 * in its own address - the signature is one - so each answers no-store, which keeps it out of
 * every shared cache and off the disk of the device the mail was read on, and no-referrer, which
 * keeps the link out of the Referer header of anything the page loads or links to. That is the
 * same pair, for the same reason, as the page the customer magic link lands on.
 *
 * The refusal is here rather than an abort, because a person clicking a link in their mail is
 * owed a sentence rather than a stack trace, and because the body has to be the same for every
 * reason a link can fail: this is the one place that body is written.
 *
 * **A page answers in its link's language**: the locale SetTenantLocale set for the request, the
 * prefix the link was minted under, else the tenant default for a bare link. Its words are
 * decision.php's, read here for DecisionWords' and OutcomeWords' keys and in place by the views
 * for their own, and the booking's facts are spelled in the same language, its instants in the
 * tenant's clock with the date order and the dial its settings row holds.
 */
final readonly class DecisionPages
{
    public function __construct(
        private ViewFactory $views,
        private Translator $translator,
        private SettingsReader $settings,
    ) {}

    /**
     * The page a live link opens: what the booking is, who asked, when it is, and one button
     * for the one decision the link carries.
     *
     * The action is a link minted again rather than the address this request arrived at. What
     * the POST has to present is a URL the signature was computed over, and re-minting is the
     * one way to be sure of that: a reconstruction of a query string depends on the order and
     * the encoding a proxy or a framework happened to hand back.
     */
    public function confirm(Tenant $tenant, BookingSnapshot $booking, SignedDecision $signed, string $action): Response
    {
        $language = $this->language();
        $decision = DecisionWords::of($signed->decision);

        return $this->page('decision.confirm', [
            'tenant' => $tenant->name,
            'decision' => [
                'verb' => DecisionLines::line($decision['verb'], $language),
                'consequence' => DecisionLines::line($decision['consequence'], $language),
                'reason' => $decision['reason'],
            ],
            'action' => $action,
            'facts' => BookingFacts::of($booking, $this->clockOf($tenant), $signed->managesBookings(), $language),
        ], Response::HTTP_OK);
    }

    /**
     * What a link gets when there is nothing left to decide: where the booking stands now, and,
     * for a request whose window closed before anybody answered, what the policy will make of
     * it, where the user the link names may be shown that.
     */
    public function outcome(Tenant $tenant, BookingSnapshot $booking, SignedDecision $signed, ApprovalWindow $window): Response
    {
        $language = $this->language();

        return $this->page('decision.outcome', [
            'tenant' => $tenant->name,
            'words' => array_map(fn (string $key): string => DecisionLines::line($key, $language), OutcomeWords::for($booking->status, $window)),
            'facts' => BookingFacts::of($booking, $this->clockOf($tenant), $signed->managesBookings(), $language),
        ], Response::HTTP_OK);
    }

    /**
     * One body for every way a link can fail to authorize, naming no booking, no customer and
     * no decision. It names the tenant no more than it names the booking: this is also the
     * answer to a link minted for another tenant, and saying whose subdomain it landed on would
     * be telling the holder of a forged link something about where they are.
     */
    public function refused(): Response
    {
        return $this->page('decision.refused', [], Response::HTTP_FORBIDDEN);
    }

    /**
     * The page's language, the request's as SetTenantLocale set it.
     */
    private function language(): SiteLanguage
    {
        return SiteLanguage::from($this->translator->getLocale());
    }

    /**
     * The tenant's clock, its formats read off its settings row as the page renders.
     */
    private function clockOf(Tenant $tenant): TenantClock
    {
        return TenantClock::of($tenant, $this->settings->read($tenant));
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data, int $status): Response
    {
        return new Response($this->views->make($view, $data)->render(), $status, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
