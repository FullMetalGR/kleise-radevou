<?php

declare(strict_types=1);

namespace App\Help;

/**
 * A help ticket's row as a write locked it: its two names, its business, its subject and its
 * status.
 */
final readonly class HelpTicketRef
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $tenantId,
        public string $subject,
        public HelpStatus $status,
    ) {}
}
