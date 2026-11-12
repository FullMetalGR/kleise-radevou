<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;

/**
 * What the store assigned the booking step 6 inserted: the public identifier its generator
 * made and the expiry its clock set, the TTL after the row's own created_at, or no expiry at
 * all where the landing was settled and carried no lifetime, Landing. Nothing else
 * comes back, because everything else was decided before the transaction opened. The manage
 * token is not here and never reaches the store: the use case mints it, hands the store the
 * hash, and carries the plaintext out itself.
 */
final readonly class InsertedHold
{
    public function __construct(
        public string $uuid,
        public ?DateTimeImmutable $holdExpiresAt,
    ) {}
}
