<?php

declare(strict_types=1);

namespace App\Application\Payment;

use SensitiveParameter;

/**
 * How the manage token travels from the pay hop to the confirmation that follows the payment,
 * the third moment it exists: sealed with the application's key onto the booking row by the
 * hop, opened once by the paid transition, which carries it on to the send's encrypted payload
 * and clears the row in the same write.
 */
interface ManageTokenSeal
{
    public function seal(#[SensitiveParameter] string $plain): string;

    /**
     * The plaintext, or null for a ciphertext this application's key does not open, which
     * degrades to a confirmation that says where the customer's link is.
     */
    public function open(string $sealed): ?string;
}
