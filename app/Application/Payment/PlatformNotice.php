<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * A notification verified with the platform's own signing secret: its event, the connected
 * account it concerns where it names one, and, where it is an account update, where that account
 * now stands; where it says a payment taken on that account succeeded, the payment notice it
 * carries, read as own mode's are. Any other event is recorded and acts on nothing.
 */
final readonly class PlatformNotice
{
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $accountRef = null,
        public ?AccountStanding $standing = null,
        public ?PaymentNotice $payment = null,
    ) {}
}
