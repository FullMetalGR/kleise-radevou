<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;

/**
 * 57014: the statement timeout cancelled the transaction. The time budget is blown, which is
 * an alert, not a retry: a second run would blow it again.
 */
final class BudgetExceeded extends TransactionFailure
{
    public static function on(string $tenant, PDOException $cause): self
    {
        return new self(SqlState::QueryCanceled, $tenant, sprintf(
            'Tenant "%s": the transaction blew its time budget and was cancelled.',
            $tenant,
        ), $cause);
    }
}
