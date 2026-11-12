<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use PDOException;
use RuntimeException;

/**
 * A booking transaction ended by one of the SQLSTATEs in the error table, already rolled back.
 * The subclasses are the table's rows; a caller branches on the type, never on the code, and
 * the driver's exception stays reachable as the previous one.
 */
abstract class TransactionFailure extends RuntimeException
{
    protected function __construct(
        public readonly SqlState $sqlstate,
        public readonly string $tenant,
        string $message,
        PDOException $cause,
    ) {
        parent::__construct($message, 0, $cause);
    }
}
