<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Dto\BookingSnapshot;

/**
 * The payment port: the five things the rest of the system may ask of whoever takes a tenant's
 * money. Every use case, every controller and the whole admin talk to this and never learn
 * whose payment they hold.
 *
 * It is the tenant's customers' money and has nothing to do with the platform's own billing:
 * the two share a vendor at v1 and no class, config key, table or fake.
 *
 * No implementation may throw at a caller for the provider's sake: a provider that cannot
 * answer is an answer of null or of nothing done, reported by the adapter itself.
 */
interface PaymentProvider
{
    /**
     * A working connection exists. Answered from what is stored, never by asking the provider,
     * so no booking's path depends on somebody else's uptime.
     */
    public function isAvailable(): bool;

    /**
     * Where to send the customer to pay for this booking, or null where there is nowhere. The
     * request names the session already opened for the booking, which is reused rather than a
     * second one created, and the end the session is asked to have, the hold's; the answer is
     * the session's page, its id and the end the provider gave it. Where the provider knows
     * why it opens nothing and the customer must be told, it says so: the recorded session
     * was completed and its payment is on its way, or the hold is too short for any session
     * it would open (SessionWithheld).
     */
    public function checkoutUrl(CheckoutRequest $request): CheckoutSession|SessionWithheld|null;

    /**
     * The provider's object id for this booking's payment, once one has been created.
     */
    public function paymentRef(BookingSnapshot $booking): ?string;

    /**
     * Leave a note on the payment named, the one that moved for this booking. Never a refund:
     * no operation of this port gives money back. Only a provider whose descriptor declares
     * supportsRefundFlag is asked; every other answers nothing done.
     */
    public function flagPayment(BookingSnapshot $booking, string $paymentRef, string $note): void;

    /**
     * End the booking's open checkout session before its own end, so a hold that ended unpaid,
     * by a lapse, a release, a cancel or the on-site settlement at the hop, takes its payment
     * page with it. Only a provider whose descriptor declares canExpireCheckout does anything;
     * every other answers nothing done.
     */
    public function expireCheckout(BookingSnapshot $booking, string $paymentRef): void;
}
