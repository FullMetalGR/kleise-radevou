<?php

declare(strict_types=1);

namespace App\Help;

use DateTimeImmutable;

/**
 * One ticket as the console lists it: its id and uuid, its business by id, name and slug (null
 * once the business was dropped), its subject and status, its last message's instant, and whether
 * it awaits a reply, open with the business having written last.
 */
final readonly class DeskTicket
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $tenantId,
        public string $tenantName,
        public ?string $tenantSlug,
        public string $subject,
        public HelpStatus $status,
        public DateTimeImmutable $lastMessageAt,
        public bool $awaiting,
    ) {}
}
