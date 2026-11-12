<?php

declare(strict_types=1);

namespace App\Tenancy;

use Closure;
use Illuminate\Database\Connection;
use LogicException;

/**
 * The house rule for a tenant migration that takes ACCESS EXCLUSIVE on a table: the step runs under
 * a local lock_timeout and fails fast rather than waiting.
 *
 * PostgreSQL waits for a lock forever by default, and an ALTER TABLE asking for ACCESS EXCLUSIVE
 * waits behind every open transaction that has read the table. While it waits, every later reader
 * and writer of that table queues behind it, so a long reader would stall a schema's bookings for
 * as long as it lasts. Under this rule the step gives up after LOCK_TIMEOUT, the migration's schema
 * rolls back whole and tenant:migrate stops there with that schema named, to be run again. The
 * value is below TransactionRunner's three seconds, so a booking queued behind a waiting step is
 * released before its own lock timeout answers busy.
 *
 * SET LOCAL lasts to the end of the transaction, a savepoint's release included, so the value the
 * transaction held before the step is put back after it: the migrations after this one, and the
 * rest of a provisioning build, keep whatever they ran under. A failed step needs no restore, its
 * rollback undoing the SET. SET LOCAL outside a transaction does nothing, so the absence of one is
 * refused rather than silently ignored; the framework's migrator opens one around every migration.
 */
final readonly class ExclusiveStep
{
    /**
     * How long a tenant migration's ACCESS EXCLUSIVE step waits for its lock before it fails.
     */
    public const string LOCK_TIMEOUT = '1s';

    /**
     * Runs the step with the lock timeout set locally and answers what it answers.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    public static function run(Connection $connection, Closure $step): mixed
    {
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('The lock timeout is set with SET LOCAL, which needs an open transaction.');
        }

        $before = $connection->scalar("select current_setting('lock_timeout')");

        if (! is_string($before)) {
            throw new LogicException('The connection reported no lock timeout.');
        }

        $connection->statement(sprintf("set local lock_timeout = '%s'", self::LOCK_TIMEOUT));

        $answer = $step();

        $connection->statement("select set_config('lock_timeout', ?, true)", [$before]);

        return $answer;
    }
}
