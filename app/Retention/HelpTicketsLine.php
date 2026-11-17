<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The businesses' help tickets: a thread of messages between a business's users and the platform,
 * kept twelve months from its last message, open or closed, then deleted, its messages and its
 * read marks with it by their cascade. Closing and reopening a ticket leave its last message's
 * time where it was, so neither keeps a ticket longer. The platform audit log's rows about a
 * ticket hold no message and are kept to their own period.
 */
final readonly class HelpTicketsLine implements RetentionLine
{
    /** How long a ticket is kept from its last message. */
    public const string KEPT = 'P12M';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'help_tickets';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            'delete from help_tickets where last_message_at < ?::timestamptz',
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
