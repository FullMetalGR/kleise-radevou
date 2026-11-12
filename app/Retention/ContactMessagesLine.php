<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The site's contact messages: a prospect's name, address and message, kept twelve months
 * from created_at, archived or not, then deleted. The platform audit log's contact_archived
 * rows name a message by its id alone and are kept to their own period.
 */
final readonly class ContactMessagesLine implements RetentionLine
{
    /** How long a message is kept from its created_at. */
    public const string KEPT = 'P12M';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'contact_messages';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            'delete from contact_messages where created_at < ?::timestamptz',
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
