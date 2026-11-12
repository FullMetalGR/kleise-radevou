<?php

declare(strict_types=1);

namespace App\Application\Payment;

use RuntimeException;
use Throwable;

/**
 * A checkout session that outlived its hold could not be expired: anything the expiry job did
 * threw, a read of the tenant or the booking as well as the provider's call. Reported once by
 * the job, after the commit that ended the hold, and never thrown at a caller: the hold stays
 * ended, and a payment that lands at the session anyway is a late payment.
 *
 * The message names the booking and the class thrown, and carries neither the thrown message
 * nor the exception, which may hold a credential, an address, a request body or a statement.
 */
final class CheckoutNotExpired extends RuntimeException
{
    public static function for(string $uuid, Throwable $failure): self
    {
        return new self(sprintf('The checkout session of booking %s could not be expired, failing with %s; the hold stays ended and a payment landing at the session is a late payment.', $uuid, $failure::class));
    }
}
