<?php

declare(strict_types=1);

namespace App\Application;

/**
 * What a payment notification did to its booking, read under the lock.
 */
enum PaidOutcome
{
    /** A held booking waiting for its payment, landed on confirmed. */
    case Confirmed;

    /**
     * Already confirmed, or past it. A no-op only where the row says the notice is another of the
     * payment that confirmed the booking, CheckoutState::confirmedBy; any other is a late payment.
     */
    case AlreadySettled;

    /** No longer held, or never held for a payment: lapsed, released, cancelled, rejected or not paid online. Nothing confirmed. */
    case NotHeld;

    /** No booking of this tenant carries the identifier. */
    case Unknown;
}
