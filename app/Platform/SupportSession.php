<?php

declare(strict_types=1);

namespace App\Platform;

use DateTimeImmutable;

/**
 * An open support session as the tenant host and the console read it: the row's id, the
 * operator acting in it by id and by name, and the instants it was opened and ends at, in UTC.
 */
final readonly class SupportSession
{
    public function __construct(
        public int $id,
        public int $operatorId,
        public string $operatorName,
        public DateTimeImmutable $openedAt,
        public DateTimeImmutable $endsAt,
    ) {}
}
