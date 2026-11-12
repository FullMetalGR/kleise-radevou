<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 23505: a unique constraint refused a duplicate. Treated as 23P01, since no benign key race
 * is known.
 */
final class UniqueViolation extends ProtocolHole
{
    public static function on(string $tenant, PDOException $cause): self
    {
        return new self(SqlState::UniqueViolation, $tenant, sprintf(
            'Tenant "%s": a unique constraint refused a duplicate row. Treated as a protocol hole.',
            $tenant,
        ), $cause);
    }
}
