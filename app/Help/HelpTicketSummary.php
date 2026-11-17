<?php

declare(strict_types=1);

namespace App\Help;

use DateTimeImmutable;

/**
 * One row of a business's ticket list: its uuid, subject and status, its last message's instant,
 * and whether it is unread for the user who reads the list.
 */
final readonly class HelpTicketSummary
{
    public function __construct(
        public string $uuid,
        public string $subject,
        public HelpStatus $status,
        public DateTimeImmutable $lastMessageAt,
        public bool $unread,
    ) {}
}
