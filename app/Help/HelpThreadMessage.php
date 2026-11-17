<?php

declare(strict_types=1);

namespace App\Help;

use DateTimeImmutable;

/**
 * One message of a help thread as stored: its side, its writer by reference, a user's id or an
 * operator's, and by name as written, its text and its instant. Which name a reader is shown is
 * the reader's side to decide: the business reads the platform's support name in place of an
 * operator's.
 */
final readonly class HelpThreadMessage
{
    public function __construct(
        public int $id,
        public HelpSide $side,
        public ?int $userId,
        public ?int $operatorId,
        public string $authorName,
        public string $body,
        public DateTimeImmutable $at,
    ) {}
}
