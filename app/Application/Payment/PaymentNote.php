<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * The words left on a payment that bought nothing the booking still holds. The note names the
 * booking by its public identifier and says that nothing has been refunded, so the tenant who
 * finds it in their provider's dashboard knows the refund is theirs to make there. Nothing in
 * this product refunds.
 */
final class PaymentNote
{
    public static function cancelled(string $uuid): string
    {
        return sprintf('Booking %s was cancelled. The slot is released and nothing has been refunded.', $uuid);
    }

    /**
     * A payment that reached a booking no longer waiting for it: its hold lapsed, was released
     * or was cancelled, it was settled on site, or another payment confirmed it.
     */
    public static function late(string $uuid): string
    {
        return sprintf('Booking %s was no longer waiting for this payment when it arrived, so the payment bought nothing. Nothing has been refunded.', $uuid);
    }
}
