<?php

declare(strict_types=1);

namespace App\Application\Payment;

use RuntimeException;
use Throwable;

/**
 * A provider that threw when asked for a checkout. What is reported names the booking and the
 * class of what was thrown and carries neither its message nor the exception itself: a
 * provider's error can echo what it was sent, the return URL among it, and that URL carries a
 * credential on both roads to the hop, the manage token on one and the payment link's
 * signature on the other; a report is a log line.
 */
final class CheckoutNotOpened extends RuntimeException
{
    public static function for(string $uuid, Throwable $failure): self
    {
        return new self(sprintf('The payment provider failed to open a checkout for booking %s with %s; the hold stands until its own deadline.', $uuid, $failure::class));
    }
}
