<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;

/**
 * One of the staff's notes on a customer's address: the row customer_notes holds, its id, the
 * text as written, the name its author wrote it under, kept as written so a note still names a
 * user the tenant no longer has, and the instant it was written. The author's actor stays on the
 * row and is not read: the page names a person, not an id.
 */
final readonly class CustomerNote
{
    public function __construct(
        public int $id,
        public string $body,
        public string $author,
        public DateTimeImmutable $createdAt,
    ) {}
}
