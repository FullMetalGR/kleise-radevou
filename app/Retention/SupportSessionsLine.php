<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * The console's support sessions: references and instants, kept ninety days after the session's
 * end, then deleted. The end is ended_at where an operator ended it and expires_at where nobody
 * did, since the sixty minutes end a session by themselves and write nothing. An end is only ever
 * written before the expiry, so a session ended early is counted from its end and never from the
 * later expiry.
 */
final readonly class SupportSessionsLine implements RetentionLine
{
    /** How long a session is kept from its end. */
    public const string KEPT = 'P90D';

    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'support_sessions';
    }

    public function apply(): int
    {
        return $this->db->connection()->transaction(fn (Connection $db): int => $db->delete(
            'delete from support_sessions where coalesce(ended_at, expires_at) < ?::timestamptz',
            [Cutoff::before($this->clock, self::KEPT)],
        ));
    }
}
