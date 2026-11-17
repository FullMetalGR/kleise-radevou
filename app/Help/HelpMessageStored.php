<?php

declare(strict_types=1);

namespace App\Help;

use App\Tenancy\Role;
use DateTimeImmutable;

/**
 * A help message as it was stored, handed to the hook once its transaction has committed: the
 * ticket's id, which the console names it by, and its uuid, which the business's side names it by,
 * the business, the ticket's subject, whether this message opened the ticket, and the message
 * itself, its side, its writer and their name as written, the business writer's role, the text and
 * the instant. The platform's writer is an operator, with no role.
 */
final readonly class HelpMessageStored
{
    public function __construct(
        public int $messageId,
        public int $ticketId,
        public string $ticketUuid,
        public int $tenantId,
        public string $subject,
        public bool $opened,
        public HelpSide $side,
        public ?int $userId,
        public ?int $operatorId,
        public string $authorName,
        public ?Role $role,
        public string $body,
        public DateTimeImmutable $at,
    ) {}
}
