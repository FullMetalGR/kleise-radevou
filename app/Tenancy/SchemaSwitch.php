<?php

declare(strict_types=1);

namespace App\Tenancy;

use Closure;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Points unqualified names at a tenant schema for one piece of work, then back at the path the
 * transaction held before. The build runs every tenant migration and writes the first rows
 * through it, and tenant:migrate runs each built schema's pending migrations through it, inside
 * the transaction each of them opens.
 *
 * SET LOCAL rather than SET: transaction-scoped, so it reverts on commit and cannot outlive the
 * transaction on a pooled connection, which is the rule for every schema switch. That also means
 * it needs an open transaction to have any effect at all, so the absence of one is refused rather
 * than silently ignored. The path is put back on success because a caller already inside a
 * transaction, the test wrapper being one, would otherwise stay on the tenant path until its own
 * commit; on failure the rollback undoes the switch.
 */
final readonly class SchemaSwitch
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function within(Connection $connection, string $schemaName, Closure $work): mixed
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('The search path is switched with SET LOCAL, which needs an open transaction.');
        }

        $central = $connection->scalar("select current_setting('search_path')");

        if (! is_string($central)) {
            throw new LogicException('The connection reported no search path.');
        }

        $connection->statement('set local search_path to '.SearchPath::forSchema($schemaName));

        $answer = $work();

        $connection->statement('set local search_path to '.$central);

        return $answer;
    }
}
