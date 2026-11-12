<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;

/**
 * What a manual booking hands back: the public identifier of the booking, the total the
 * customer will be asked for, and the manage token in plaintext. No expiry, because the
 * booking landed confirmed and nothing about it lapses.
 *
 * The token lives here and nowhere else in the clear, the same rule as the hold's Held: only
 * its hash was written, and the one durable copy the customer gets is the link in their
 * confirmation, which the listener carrying it builds from the record this token also rides
 * on. The operator is never shown it; the answer they get is a sentence.
 */
final readonly class Booked
{
    public function __construct(
        public string $uuid,
        public Money $total,
        public string $manageToken,
    ) {}
}
