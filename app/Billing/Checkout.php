<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * A hosted checkout the provider has opened: the customer it is for, created by the provider when
 * the caller had none, the session's own identifier, and where to send the person. The signup
 * records all three with the instant it opened, so a holder coming back is sent to the same
 * session rather than a second one beside it.
 */
final readonly class Checkout
{
    public function __construct(
        public string $customerId,
        public string $sessionId,
        public string $url,
    ) {}
}
