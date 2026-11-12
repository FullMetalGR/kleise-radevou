<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 25P02: a statement was issued into a transaction an earlier error had already aborted. Only
 * code that caught that error and carried on can raise this, so it is a bug, and almost always
 * a handler written for an engine where a statement error leaves the transaction alive.
 */
final class AbortedTransactionReused extends TransactionFailure
{
    public static function on(string $tenant, PDOException $cause): self
    {
        return new self(SqlState::InFailedSqlTransaction, $tenant, sprintf(
            'Tenant "%s": a statement was issued into an aborted transaction. This is a bug: code continued after an error instead of letting the transaction roll back.',
            $tenant,
        ), $cause);
    }
}
