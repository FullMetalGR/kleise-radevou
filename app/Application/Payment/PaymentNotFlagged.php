<?php

declare(strict_types=1);

namespace App\Application\Payment;

use RuntimeException;
use Throwable;

/**
 * A note could not be left on a payment: anything the flag job did threw, a read of the
 * tenant or the booking as well as the provider's call. Reported once by the job, after the
 * commit, and never thrown at a caller: the booking stands as it landed.
 *
 * The message names the booking and the class thrown, and carries neither the thrown message
 * nor the exception, which may hold a credential, an address, a request body or a statement.
 */
final class PaymentNotFlagged extends RuntimeException
{
    public static function for(string $uuid, Throwable $failure): self
    {
        return new self(sprintf('The note on the payment of booking %s could not be left, failing with %s; nothing was refunded and the booking stands as it landed.', $uuid, $failure::class));
    }
}
