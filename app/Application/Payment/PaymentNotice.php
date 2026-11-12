<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Money\Money;

/**
 * A verified payment notification as its adapter read it: the provider's event id, which is the
 * idempotency key, its type verbatim, and, for a notice saying money moved, the booking the
 * payment is for, the payment's own id and the amount the provider says was paid. Nothing in it
 * is trusted about the booking's state; the booking is read again under its lock. The amount is
 * recorded on the paid transition's trail row and compared with nothing: a payment that reached
 * a session before a move's expiry did still confirms, and the row says what was paid.
 *
 * An empty payment id is read as none: no provider names a payment with an empty string, and
 * one an adapter passed through would be written over the recorded session and later named in a
 * note, so it keeps the session as a notice with no id does.
 */
final readonly class PaymentNotice
{
    public ?string $paymentRef;

    /**
     * @param  bool  $paid  whether the notice says a payment for a booking succeeded; every other notice is recorded and acted on by nobody
     * @param  string|null  $booking  the booking's uuid as the provider carried it
     * @param  string|null  $paymentRef  the provider's id of the payment, an empty one read as none
     * @param  Money|null  $amount  what the provider says was paid, in that currency's minor units; null where it said nothing it could be read as
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public bool $paid,
        public ?string $booking = null,
        ?string $paymentRef = null,
        public ?Money $amount = null,
    ) {
        $this->paymentRef = $paymentRef === '' ? null : $paymentRef;
    }
}
