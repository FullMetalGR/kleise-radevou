<?php

declare(strict_types=1);

namespace App\Application\Payment\Stripe;

use RuntimeException;

/**
 * No answer from Stripe: the connection failed or the request timed out. Its message is fixed
 * and it wraps nothing, since the client's own exception carries the request and its headers.
 */
final class StripeUnreachable extends RuntimeException
{
    public static function make(): self
    {
        return new self('Stripe could not be reached.');
    }
}
