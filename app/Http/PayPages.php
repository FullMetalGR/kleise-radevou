<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Payment\CheckoutState;
use App\Application\Payment\HopVerdict;
use App\Domain\Enum\BookingStatus;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;

/**
 * The worded pages the pay hop answers a person with, in place of the provider's page. None
 * names the booking or the customer; the payment link's status page names the tenant alone.
 *
 * Every sentence on them is a key of pages.php read in the request's language, the locale
 * SetTenantLocale set from the path's prefix else the tenant default: the four worded pages read
 * theirs in their views, and the status page's are chosen here as keys and read here; the manage
 * guard's refusal is rendered from the exception handler inside the tenant group, so it reads the
 * same locale.
 */
final readonly class PayPages
{
    /**
     * The status page's second sentence while the booking waits for its payment, written for the
     * first visit, on which no payment page exists yet, and for every visit after it: it
     * promises no refresh the page does not make, and says what a press does before the payment
     * and after it, a press after it answering that the payment is on its way.
     */
    public const string JUST_PAID = 'pages.pay.waiting.just_paid';

    public function __construct(private ViewFactory $views, private Translator $translator) {}

    /**
     * A hold that lapsed, was released or was cancelled: nothing to pay, and nothing asked of
     * the provider.
     */
    public function ended(): Response
    {
        return $this->page('pay.ended', Response::HTTP_CONFLICT);
    }

    /**
     * The provider failed or answered nowhere to go: try the link again; the hold stands. A 502,
     * the provider's failure.
     */
    public function unopened(): Response
    {
        return $this->page('pay.unopened', Response::HTTP_BAD_GATEWAY);
    }

    /**
     * A hold too short for any session the provider would open, after a move cleared the one an
     * earlier press opened: the hold may not be lengthened to take one, so it can no longer be
     * paid online. A 409, the hold's state against the press, never the 502 of a provider that
     * answered nowhere to go, so status monitoring tells the two apart; nothing has been charged
     * and the hold ends at its own deadline.
     */
    public function closed(): Response
    {
        return $this->page('pay.closed', Response::HTTP_CONFLICT);
    }

    /**
     * The hop's write lost a race on a hold still live and payable, a staff-day held past the
     * lock timeout, retries exhausted, the booking moved under the lock on every attempt, or a
     * concurrent first press of the same booking that recorded its session first (the retry
     * reuses that session): the unopened page's words, since the customer's next step is the
     * same, at the house's lock-contention 503 with its Retry-After, so status monitoring never
     * reads contention as a provider outage.
     */
    public function lostRace(): Response
    {
        return $this->page('pay.unopened', Response::HTTP_SERVICE_UNAVAILABLE, headers: ['Retry-After' => ErrorEnvelope::RETRY_AFTER_BUSY]);
    }

    /**
     * The manage guard's refusal on this page: one body for a wrong, expired or missing token
     * and an unknown booking, at the guard's own status, 404, or 400 for no token at all. The
     * payment link's refusal is the same body at the decision links' 403.
     */
    public function refused(int $status): Response
    {
        return $this->page('pay.refused', $status);
    }

    /**
     * The status page of the approved message's payment link: what the link's GET renders, and
     * where the provider brings the customer back. It reads the booking as it stands and decides
     * nothing, so a mail scanner's fetch changes nothing; the one control it offers, while the
     * payment hold is live, is the button whose POST is the hop. It names the business and a
     * status, and nothing about the customer.
     *
     * Its sentences cover a customer back from the provider before the notification lands: the hold
     * still reads live and the button is still offered, the button reopens the session already
     * recorded rather than a second one, and a press after paying is answered here with the
     * sentence that the payment is on its way.
     *
     * @param  string|null  $notice  the sentence the hop's redirect flashed, shown once above the page's own
     */
    public function status(string $tenantName, ?CheckoutState $state, string $action, ?string $notice = null): Response
    {
        [$title, $lines] = self::words($state);

        return $this->page('pay.status', Response::HTTP_OK, [
            'tenant' => $tenantName,
            'title' => $this->line($title),
            'notice' => $notice,
            'lines' => array_map($this->line(...), $lines),
            'action' => $state !== null && $state->payable() ? $action : null,
        ]);
    }

    /**
     * The heading and the sentences for the booking as it stands, read off the hop's one mapping
     * (HopVerdict::of), so the page says what a press of its button would answer: waiting for its
     * payment where the hop would go on to the provider; no longer held where it would read the
     * ended page, no row included; and where it would go back to be read, one form per status,
     * confirmed, past, and anything else, which has nothing to pay. Each is a key of pages.php, the
     * no longer held heading and sentence the ended page's own, which status() reads in the
     * request's language.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function words(?CheckoutState $state): array
    {
        $nothing = 'pages.pay.nothing_more';

        return match (HopVerdict::of($state)) {
            HopVerdict::Provider => ['pages.pay.waiting.title', ['pages.pay.waiting.approved', self::JUST_PAID]],
            HopVerdict::Ended => ['pages.pay.ended.title', ['pages.pay.ended.given_back']],
            default => match ($state?->status) {
                BookingStatus::Confirmed => ['pages.pay.confirmed', [$nothing]],
                BookingStatus::Completed, BookingStatus::NoShow => ['pages.pay.passed', [$nothing]],
                default => ['pages.pay.nothing_to_pay', [$nothing]],
            },
        };
    }

    /**
     * One line of pages.php in the request's language, the locale SetTenantLocale set; a key the
     * file lacks reads as the key, as the translator answers it.
     */
    private function line(string $key): string
    {
        $line = $this->translator->get($key);

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers  beside the three every page carries
     */
    private function page(string $view, int $status, array $data = [], array $headers = []): Response
    {
        return new Response($this->views->make($view, $data)->render(), $status, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
            ...$headers,
        ]);
    }
}
