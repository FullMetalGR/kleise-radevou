<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 23P01: the exclusion constraint on booking_items refused an overlapping item.
 */
final class ExclusionViolation extends ProtocolHole
{
    public static function on(string $tenant, PDOException $cause): self
    {
        return new self(SqlState::ExclusionViolation, $tenant, sprintf(
            'Tenant "%s": the exclusion constraint refused an overlapping item. The write protocol has a hole; the constraint held.',
            $tenant,
        ), $cause);
    }
}
