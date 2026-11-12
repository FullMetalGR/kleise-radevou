<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\BookingUnknown;
use App\Application\Dto\BookingSnapshot;
use App\Application\Payment\CheckoutHop;
use App\Application\Payment\CheckoutNotOpened;
use App\Application\Payment\HopVerdict;
use App\Application\TransitionRefused;
use App\Application\WriteContended;
use App\Infrastructure\BookingTransitionFactory;
use App\Infrastructure\Db\Busy;
use App\Infrastructure\Db\RetriesExhausted;
use App\Infrastructure\PaymentFactory;
use App\Tenancy\Tenant;
use Illuminate\Contracts\Debug\ExceptionHandler;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pay hop's answer to a person, on both roads to it: the manage token's GET /pay/{uuid} and
 * the approved message's signed link, whose POST is the act. Where the customer reads their
 * booking afterwards is the one thing the roads differ in: the manage page under the token, the
 * link's own status page under the signature, since no plaintext token exists on that road.
 *
 * A live payable hold goes on to the provider's page; a booking that settled goes back to where
 * it is read; a hold that lapsed, was released or was cancelled reads a worded page and the
 * provider is never asked; a provider that failed is reported once and the customer reads a
 * worded page at 502, the hold standing until its own deadline; a provider that answered no
 * session reads the same page at 502 and nothing is reported, the hold as it stood; a write that
 * lost a race on a hold still payable reads the same words at 503 with a Retry-After, and neither
 * 502 carries one. Where no provider works, the hold settles on site through the shared guarded
 * write, whether or not an earlier press opened a session, and the customer goes back to read it
 * confirmed, told that they pay at the venue; a second visit finds it confirmed and goes straight
 * back.
 *
 * Two provider answers are told as they are rather than as the 502: a press again after the
 * customer paid and before the payment's notification landed goes back to be read, told the
 * payment is on its way; a hold too short for any session the provider would open reads a page of
 * its own at 409, saying it can no longer be paid online. An on-site settlement that lost its
 * race goes back told so. Each sentence rides the flash to the page the customer reads the
 * booking on, CustomerNotice, as its key of pages.php, which that page reads in its own language,
 * and names no credential.
 *
 * The way back is the caller's and keeps the prefix the hop was asked under, so the redirect and
 * the provider's return land on the page in the hop's language.
 *
 * Neither answer carries a referrer onward, since both addresses hold a credential, and neither
 * is logged.
 */
final readonly class PayHopAnswer
{
    /** A press again after the customer paid, before the payment's notification landed. */
    public const string PAYMENT_ON_ITS_WAY = 'pages.pay.flashes.on_its_way';

    /** The on-site settlement's answer, payment at the venue. */
    public const string PAY_AT_THE_VENUE = 'pages.pay.flashes.at_the_venue';

    /** An on-site settlement that lost its race, the booking read as it stands. */
    public const string SETTLEMENT_LOST = 'pages.pay.flashes.settlement_lost';

    public function __construct(
        private PaymentFactory $payments,
        private BookingTransitionFactory $transitions,
        private PayPages $pages,
        private ExceptionHandler $handler,
        private CustomerNotice $notice,
    ) {}

    /**
     * @param  string  $back  where the customer reads the booking, and where the provider returns them
     * @param  string|null  $token  the plaintext the token guard verified, or null on the signed road
     */
    public function for(Tenant $tenant, BookingSnapshot $booking, #[SensitiveParameter] string $back, #[SensitiveParameter] ?string $token): Response
    {
        $hop = $this->payments->hop($tenant);

        try {
            $answer = $hop->open($booking, $back, $token);
        } catch (CheckoutNotOpened $failure) {
            $this->handler->report($failure);

            return $this->pages->unopened();
        }

        return match ($answer->verdict) {
            HopVerdict::Provider => self::onward($answer->url ?? $back),
            HopVerdict::Manage => self::onward($back),
            HopVerdict::Arriving => $this->told($back, 'info', self::PAYMENT_ON_ITS_WAY),
            HopVerdict::Ended => $this->pages->ended(),
            HopVerdict::Unopened => $this->pages->unopened(),
            HopVerdict::Closed => $this->pages->closed(),
            HopVerdict::LostRace => $this->pages->lostRace(),
            HopVerdict::OnSite => $this->onSite($tenant, $hop, $booking->uuid, $back, $token),
        };
    }

    /**
     * The on-site settlement, asked again under the lock: settled, the customer goes back to read
     * it confirmed, told they pay at the venue; a hold that ended meanwhile reads the ended page,
     * by the one mapping of the row as it stands, and anything else the guard refuses (settled
     * already, or a connection that began working) goes back to be read as it stands, told that
     * nothing was done.
     *
     * A write that lost a race is answered the same way, as the decide links answer one: the
     * booking's items moved off the locked staff-days on every attempt by a reschedule landing
     * under the press; a booking the lock no longer finds; and the busy cases, a staff-day held
     * past the lock timeout (Busy) or retries exhausted, whose failure line the runner already
     * wrote: each having written nothing. None is worth the framework's error page to a customer
     * who pressed a button; the re-read says what the booking is now, and a press again settles
     * it if it still may. Every other failure is a fault, not a race, and reaches the handler to
     * be reported.
     */
    private function onSite(Tenant $tenant, CheckoutHop $hop, string $uuid, #[SensitiveParameter] string $back, #[SensitiveParameter] ?string $token): Response
    {
        try {
            $this->transitions->confirm($tenant)->settleAtTheHop($uuid, $token);
        } catch (TransitionRefused|WriteContended|BookingUnknown|Busy|RetriesExhausted) {
            return HopVerdict::of($hop->standing($uuid)) === HopVerdict::Ended
                ? $this->pages->ended()
                : $this->told($back, 'warning', self::SETTLEMENT_LOST);
        }

        return $this->told($back, 'success', self::PAY_AT_THE_VENUE);
    }

    /**
     * Back to where the booking is read, with one sentence for that page to show once, flashed as
     * its key.
     *
     * @param  'success'|'info'|'warning'  $kind
     */
    private function told(#[SensitiveParameter] string $back, string $kind, string $key): RedirectResponse
    {
        $this->notice->flash($kind, $key);

        return self::onward($back);
    }

    private static function onward(string $url): RedirectResponse
    {
        return new RedirectResponse($url, Response::HTTP_SEE_OTHER, [
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
