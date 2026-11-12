<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * A lapsed set-password link: the link's hash is dead once its expiry has passed, and the two
 * are nulled together in one statement, which the CHECK users_password_link_check requires.
 * Passed is as the link itself reads it (App\Platform\PasswordLinks::live()): a link is live
 * while its expiry is ahead of the clock's now, so one whose expiry is the clock's now is
 * lapsed and nulled. The user stays; updated_at takes the clock's now, the instant the row
 * changed.
 */
final readonly class PasswordLinksLine implements RetentionLine
{
    public function __construct(private DatabaseManager $db, private Clock $clock) {}

    public function table(): string
    {
        return 'users';
    }

    public function apply(): int
    {
        $now = SqlText::instant($this->clock->now());

        return $this->db->connection()->transaction(fn (Connection $db): int => $db->update(
            'update users set password_link_hash = null, password_link_expires_at = null, updated_at = ?::timestamptz
             where password_link_expires_at <= ?::timestamptz',
            [$now, $now],
        ));
    }
}
