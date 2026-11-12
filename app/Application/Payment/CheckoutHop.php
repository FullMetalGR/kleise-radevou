<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Dto\BookingSnapshot;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\PaymentMode;
use SensitiveParameter;
use Throwable;

/**
 * The customer's way from a held booking to the provider's page, for one tenant.
 *
 * offers() is the hold answer's question: whether checkout_url belongs on it. paysAtVenue() is
 * its other side: a live online hold that no working provider can take the money for, which
 * the confirm settles unpaid and the customer pays for at the venue. Both read our own row and
 * the stored connection and ask the provider nothing.
 *
 * open() is the hop. It reads the row first, so a hold that is no longer live is refused before
 * the provider is asked. A payable hold under no working provider asks no provider at all and
 * answers on site, whether or not an earlier visit recorded a session: the caller settles it
 * through the shared guarded write, and payment_ref stays as recorded. Under a working provider
 * a payable hold asks it for a session ending with the hold, reusing the session already
 * recorded on a second visit; the provider is asked outside any transaction and under no lock.
 * On the first visit, or the first after a move of the booking cleared its session, the
 * session's id and end are written in one guarded statement, the hold's expiry becoming the
 * session's end; a hold that ended while the provider was being asked is not revived and
 * nothing is written. A second visit moves nothing. The request tells the provider how many
 * sessions moves have cleared, read off the trail: a session after a move ends with the hold as
 * it stands rather than the floor past it again, so the hold is lengthened past its term once,
 * and the count keeps the provider's idempotency key off a session a move expired.
 *
 * A provider that opens no page says why where the customer must be told: a recorded session it
 * completed, whose payment is on its way before its notification lands, is Arriving, and a hold
 * too short for any session it would open is Closed; a provider that answered nowhere to go is
 * Unopened. What a booking's row answers before the provider is asked is HopVerdict::of, the
 * one mapping every road reads.
 */
final readonly class CheckoutHop
{
    public function __construct(
        private CheckoutRecords $records,
        private PaymentProvider $provider,
    ) {}

    public function offers(string $uuid): bool
    {
        return $this->payableOnline($uuid) && $this->provider->isAvailable();
    }

    public function paysAtVenue(string $uuid): bool
    {
        return $this->payableOnline($uuid) && ! $this->provider->isAvailable();
    }

    /** A pending hold of an online booking: one the customer would pay for before it confirms. */
    private function payableOnline(string $uuid): bool
    {
        $state = $this->records->state($uuid);

        return $state !== null
            && $state->status === BookingStatus::Pending
            && $state->paymentMode === PaymentMode::Online;
    }

    /**
     * The booking's payment state as the row holds it now, for a page that reads and decides
     * nothing: the payment link's status page, and the hop's second look after a refusal.
     */
    public function standing(string $uuid): ?CheckoutState
    {
        return $this->records->state($uuid);
    }

    /**
     * A write that lost a race (a sweep, a release, a cancel or a settlement that committed after
     * the first read and before the guarded update, a concurrent first press that recorded its
     * session first, a move under the lock on every attempt, a staff-day held past the lock
     * timeout, or retries exhausted) reads the row again and answers it by the first read's
     * mapping: a hold that ended reads Ended, a booking no longer payable goes to Manage, and a
     * hold still live and payable reads LostRace, which the caller answers as lock contention;
     * after a concurrent first press the retry it invites reuses the session that press recorded.
     * The session the provider just opened for this press is left unrecorded, as a lapsed hold
     * leaves one.
     *
     * @param  string|null  $manageToken  the plaintext token the guard verified on this request, sealed onto the row with the session; null on the signed road, which holds none and seals nothing
     *
     * @throws CheckoutNotOpened where the provider threw, for the caller to report once
     */
    public function open(BookingSnapshot $booking, #[SensitiveParameter] string $returnUrl, #[SensitiveParameter] ?string $manageToken): HopAnswer
    {
        $state = $this->records->state($booking->uuid);
        $endsBy = $state?->payableUntil();

        // Ended or Manage, by the one mapping: a hold that is not payable asks no provider.
        if ($state === null || $endsBy === null) {
            return HopAnswer::of(HopVerdict::of($state));
        }

        // With no working provider the settlement question's one answer is on site, read from
        // the connection's row and asked of no provider, and a session an earlier press
        // recorded does not change it. That session is expired after the settlement commits, as
        // any session of a hold ended unpaid is (ExpireCheckout, queued by TellTheProvider),
        // and a payment landing at it anyway takes the late-payment road. The caller settles
        // through the shared guarded write, which asks the same question again under the lock
        // and leaves payment_ref as it stands.
        if (! $this->provider->isAvailable()) {
            return HopAnswer::of(HopVerdict::OnSite);
        }

        try {
            $session = $this->provider->checkoutUrl(new CheckoutRequest($booking, $state->paymentRef, $returnUrl, $endsBy, $state->sessionsCleared));
        } catch (Throwable $failure) {
            throw CheckoutNotOpened::for($booking->uuid, $failure);
        }

        // No page, for a reason the customer is told as it is, or for none.
        if (! $session instanceof CheckoutSession) {
            return HopAnswer::of(match ($session) {
                SessionWithheld::Completed => HopVerdict::Arriving,
                SessionWithheld::TooShort => HopVerdict::Closed,
                null => HopVerdict::Unopened,
            });
        }

        if ($state->paymentRef !== null || $this->records->record($booking->uuid, $session->ref, $session->endsAt, $manageToken)) {
            return HopAnswer::provider($session->url);
        }

        // The first read's mapping again, on the row as the race left it: a hold still payable
        // lost the race.
        $verdict = HopVerdict::of($this->records->state($booking->uuid));

        return HopAnswer::of($verdict === HopVerdict::Provider ? HopVerdict::LostRace : $verdict);
    }
}
