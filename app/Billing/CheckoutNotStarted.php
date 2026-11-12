<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;
use Throwable;

/**
 * The provider could not open a checkout for a signup, which has already been given its slug
 * back. The provider's own failure is the previous exception, for the log; the person is told
 * only that nothing was charged and they may try again.
 */
final class CheckoutNotStarted extends RuntimeException
{
    public static function after(Throwable $cause): self
    {
        return new self('The billing provider could not open a checkout for a signup: '.$cause->getMessage(), previous: $cause);
    }
}
