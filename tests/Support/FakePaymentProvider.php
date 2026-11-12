<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Dto\BookingSnapshot;
use App\Application\Payment\CheckoutRequest;
use App\Application\Payment\CheckoutSession;
use App\Application\Payment\PaymentProvider;
use App\Application\Payment\SessionWithheld;
use DateInterval;
use RuntimeException;

/**
 * The fake's port over a working connection: available, a checkout session per booking created
 * once and answered again when its id is handed back, and a note kept rather than sent. Every
 * question lands on the adapter's record. A flag throws where the adapter's flagFailsWith says,
 * an expiry where its expireFailsWith does, and it stands unavailable where its unavailable does.
 *
 * The session's page is the return address itself with the session's id added, as a provider
 * adds its own parameter: the fake has no page of its own, so a customer sent to it lands back
 * on the manage page at once, still held. The fake needs no credential to open a session.
 *
 * A session's end is the end asked for moved by the adapter's lifetime, as a provider's shortest
 * session moves one; a session after a move cleared the booking's session (sessionsCleared above
 * zero) ends with the hold as asked, as the port's contract has every provider do
 * (CheckoutRequest), or is refused as too short where the adapter's laterSessionTooShort says. A
 * recorded session the adapter lists as completed answers that its payment is on its way.
 */
final readonly class FakePaymentProvider implements PaymentProvider
{
    public function __construct(private FakePaymentAdapter $adapter) {}

    public function isAvailable(): bool
    {
        return ! $this->adapter->unavailable;
    }

    public function checkoutUrl(CheckoutRequest $request): CheckoutSession|SessionWithheld
    {
        $uuid = $request->booking->uuid;
        $this->adapter->record('checkoutUrl', $uuid);

        if ($this->adapter->whileAsked !== null) {
            ($this->adapter->whileAsked)();
        }

        if ($this->adapter->failWith !== null) {
            throw new RuntimeException($this->adapter->failWith);
        }

        if ($request->paymentRef !== null && in_array($request->paymentRef, $this->adapter->completed, true)) {
            return SessionWithheld::Completed;
        }

        if ($request->paymentRef === null && $request->sessionsCleared > 0 && $this->adapter->laterSessionTooShort) {
            return SessionWithheld::TooShort;
        }

        $known = $request->paymentRef === null ? null : ($this->adapter->sessions[$request->paymentRef] ?? null);

        if ($request->paymentRef !== null && $known !== null) {
            return new CheckoutSession($known['url'], $request->paymentRef, $known['endsAt']);
        }

        $ref = sprintf('pd_%d', $this->adapter->created() + 1);
        $shift = new DateInterval(sprintf('PT%dS', abs($this->adapter->sessionOverrunSeconds)));
        $endsAt = match (true) {
            $request->sessionsCleared > 0 => $request->endsBy,
            $this->adapter->sessionOverrunSeconds >= 0 => $request->endsBy->add($shift),
            default => $request->endsBy->sub($shift),
        };
        $url = $request->returnUrl.(str_contains($request->returnUrl, '?') ? '&' : '?').'paydesk_session='.$ref;

        $this->adapter->sessions[$ref] = [
            'uuid' => $uuid,
            'url' => $url,
            'askedEnd' => $request->endsBy,
            'endsAt' => $endsAt,
            'total' => $request->booking->total,
            'returnUrl' => $request->returnUrl,
            'sessionsCleared' => $request->sessionsCleared,
        ];

        return new CheckoutSession($url, $ref, $endsAt);
    }

    public function paymentRef(BookingSnapshot $booking): ?string
    {
        $this->adapter->record('paymentRef', $booking->uuid);

        foreach ($this->adapter->sessions as $ref => $session) {
            if ($session['uuid'] === $booking->uuid) {
                return $ref;
            }
        }

        return null;
    }

    public function flagPayment(BookingSnapshot $booking, string $paymentRef, string $note): void
    {
        $this->adapter->record('flagPayment', $booking->uuid, $paymentRef, $note);

        if ($this->adapter->flagFailsWith !== null) {
            throw new RuntimeException($this->adapter->flagFailsWith);
        }
    }

    public function expireCheckout(BookingSnapshot $booking, string $paymentRef): void
    {
        $this->adapter->record('expireCheckout', $booking->uuid, $paymentRef);

        if ($this->adapter->expireFailsWith !== null) {
            throw new RuntimeException($this->adapter->expireFailsWith);
        }

        $this->adapter->expired[] = $paymentRef;
    }
}
