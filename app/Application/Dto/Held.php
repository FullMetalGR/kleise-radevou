<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * What a successful hold hands back: the public identifier of the booking, when the hold
 * lapses, the total the customer will be asked for, and the manage token in plaintext.
 *
 * The token lives in this object and nowhere else. Only its hash was written, so the hold
 * response is the one moment it can be handed to the customer; nothing can fetch it
 * afterwards, and nothing but the response may log or store it.
 */
final readonly class Held
{
    public function __construct(
        public string $uuid,
        public DateTimeImmutable $holdExpiresAt,
        public Money $total,
        public string $manageToken,
    ) {}
}
