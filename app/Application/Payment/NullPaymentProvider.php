<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Dto\BookingSnapshot;

/**
 * The provider of a tenant with nothing connected, a half-finished onboarding, a connection its
 * provider has restricted or switched off, or a stored check that failed.
 *
 * It answers every method instead of throwing, and that is the degrade rule rather than a stub:
 * nothing is available, there is nowhere to send a customer and nothing to note, so an online
 * service behaves as an on-site one and the tenant takes the money in person. It is what lets a
 * tenant take a real booking before they have chosen a provider at all.
 */
final readonly class NullPaymentProvider implements PaymentProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function checkoutUrl(CheckoutRequest $request): ?CheckoutSession
    {
        return null;
    }

    public function paymentRef(BookingSnapshot $booking): ?string
    {
        return null;
    }

    public function flagPayment(BookingSnapshot $booking, string $paymentRef, string $note): void {}

    public function expireCheckout(BookingSnapshot $booking, string $paymentRef): void {}
}
