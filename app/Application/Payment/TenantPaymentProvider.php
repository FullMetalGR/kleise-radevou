<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Dto\BookingSnapshot;

/**
 * Who answers the port for one tenant: the adapter of its primary connection while that row
 * is active and its stored check has not failed, and the null provider in every other case, no
 * row, pending, restricted, disabled, a failed check.
 *
 * The decision is read from the row each time it is asked and calls no provider, so a
 * connection switched off a moment ago is off for the next question, and no booking's path
 * depends on a provider's uptime.
 */
final readonly class TenantPaymentProvider implements PaymentProvider
{
    public function __construct(
        private PaymentConnections $connections,
        private ProviderRegistry $registry,
    ) {}

    /**
     * The provider standing behind this tenant right now.
     */
    public function current(): PaymentProvider
    {
        $connection = $this->connections->primary();

        return $connection !== null && $connection->works()
            ? $this->registry->provider($connection, $this->connections)
            : new NullPaymentProvider;
    }

    public function isAvailable(): bool
    {
        return $this->current()->isAvailable();
    }

    public function checkoutUrl(CheckoutRequest $request): CheckoutSession|SessionWithheld|null
    {
        return $this->current()->checkoutUrl($request);
    }

    public function paymentRef(BookingSnapshot $booking): ?string
    {
        return $this->current()->paymentRef($booking);
    }

    /**
     * Asked of the provider only where its descriptor declares supportsRefundFlag: the rest of
     * the system reads the descriptor and never the adapter's own answer.
     */
    public function flagPayment(BookingSnapshot $booking, string $paymentRef, string $note): void
    {
        $this->declaring(fn (ProviderDescriptor $descriptor): bool => $descriptor->supportsRefundFlag)
            ->flagPayment($booking, $paymentRef, $note);
    }

    /**
     * Asked of the provider only where its descriptor declares canExpireCheckout, and only while
     * the connection works: for a connection switched off, restricted or failing its check the
     * null provider answers, and the session ends at the provider's own end. ExpireCheckout asks
     * it after the commit of a lapse, a release, a cancel or the on-site settlement at the hop
     * that ended a checkout or a payment hold unpaid carrying a session.
     */
    public function expireCheckout(BookingSnapshot $booking, string $paymentRef): void
    {
        $this->declaring(fn (ProviderDescriptor $descriptor): bool => $descriptor->canExpireCheckout === true)
            ->expireCheckout($booking, $paymentRef);
    }

    /**
     * The provider standing behind this tenant where its descriptor declares the capability
     * asked about, and the null provider, which does nothing, where it does not.
     *
     * @param  callable(ProviderDescriptor): bool  $capability
     */
    private function declaring(callable $capability): PaymentProvider
    {
        $connection = $this->connections->primary();
        $descriptor = $connection === null ? null : $this->registry->descriptor($connection->provider);

        return $descriptor !== null && $capability($descriptor) ? $this->current() : new NullPaymentProvider;
    }
}
