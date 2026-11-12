<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Dto\BookingSnapshot;
use App\Application\Payment\CheckoutRequest;
use App\Application\Payment\CheckoutSession;
use App\Application\Payment\PaymentProvider;

/**
 * A provider that stands available and answers every checkout question with no session: the
 * port's null answer from a working connection, which the hop reads as the payment page that
 * could not be opened, answered at 502 and reported nowhere. Every question it is asked is
 * recorded on the adapter that handed it out, as FakePaymentProvider's are; it opens and
 * expires nothing.
 */
final readonly class NowherePaymentProvider implements PaymentProvider
{
    public function __construct(private FakePaymentAdapter $adapter) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function checkoutUrl(CheckoutRequest $request): ?CheckoutSession
    {
        $this->adapter->record('checkoutUrl', $request->booking->uuid);

        return null;
    }

    public function paymentRef(BookingSnapshot $booking): ?string
    {
        $this->adapter->record('paymentRef', $booking->uuid);

        return null;
    }

    public function flagPayment(BookingSnapshot $booking, string $paymentRef, string $note): void
    {
        $this->adapter->record('flagPayment', $booking->uuid, $paymentRef, $note);
    }

    public function expireCheckout(BookingSnapshot $booking, string $paymentRef): void
    {
        $this->adapter->record('expireCheckout', $booking->uuid, $paymentRef);
    }
}
