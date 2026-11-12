<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 55P03: a row lock was not granted within the lock timeout. Not retried, because the holder is
 * still there and a blind retry only hides contention; answered as "busy, try again".
 */
final class Busy extends TransactionFailure
{
    public static function on(string $tenant, PDOException $cause): self
    {
        return new self(SqlState::LockNotAvailable, $tenant, sprintf(
            'Tenant "%s" is busy: a row lock was not granted within the lock timeout. Try again.',
            $tenant,
        ), $cause);
    }
}
