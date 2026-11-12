<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 21000: one statement tried to affect a row twice, which the single-statement lock raises when
 * a duplicate staff-day pair reaches its VALUES list. De-duplication happens before SQL, so this
 * is a bug in the caller.
 */
final class CardinalityViolation extends TransactionFailure
{
    public static function on(string $tenant, PDOException $cause): self
    {
        return new self(SqlState::CardinalityViolation, $tenant, sprintf(
            'Tenant "%s": a statement affected one row twice. This is a bug: a duplicate staff-day pair reached the VALUES list, so de-duplication did not happen before SQL.',
            $tenant,
        ), $cause);
    }
}
