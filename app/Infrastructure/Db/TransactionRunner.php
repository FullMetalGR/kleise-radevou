<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Tenancy\SearchPath;
use App\Tenancy\Tenant;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The transaction every booking write runs in: step 2 of the write protocol, the retry rule and
 * the error table, on the tenant's schema.
 *
 * Top-level only. The framework's retry loop refuses to retry at depth, where a transaction is a
 * savepoint, so a caller already inside one is refused before any SQL is sent. Inside, the three
 * SET LOCAL statements come first, then READ COMMITTED is asserted, because at REPEATABLE READ
 * the protocol double-books without an error, and then current_schema() is asserted against the
 * tenant, because a pooled connection carrying another tenant's search path is a data leak. SET
 * LOCAL rather than SET: every setting reverts on commit or rollback and cannot outlive the
 * transaction on a pooled connection.
 *
 * Retry is the framework's own loop, consulting the detector bound in the service provider,
 * which matches 40001 and 40P01 by SQLSTATE and nothing else. A retry re-runs this whole
 * callback, SET LOCAL statements included, and reuses nothing from the failed attempt.
 *
 * After an error nothing here touches the connection: the framework rolls the transaction back,
 * and the mapping to a typed failure happens outside it. There is no path that continues, because
 * the next statement would fail with 25P02.
 *
 * The log is the counter and the alarm. Every failure the table names is written at the level the
 * table sets, with the tenant and the SQLSTATE in the context, so a metrics sink counts busy
 * separately from the rest and an alert fires on a blown budget or a protocol hole. Nothing from
 * the closure's SQL or bindings is logged.
 */
final readonly class TransactionRunner
{
    public const string LOCK_TIMEOUT = '3s';

    public const string STATEMENT_TIMEOUT = '10s';

    public const int ATTEMPTS = 3;

    private const string ISOLATION = 'read committed';

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $log,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     *
     * @throws PreconditionFailed when called inside a transaction, or when P4 or P5 is false
     * @throws TransactionFailure for a SQLSTATE in the error table, after the rollback
     * @throws Throwable anything else the closure or the driver raised, after the rollback
     */
    public function run(Tenant $tenant, Closure $work): mixed
    {
        $connection = $this->db->connection();
        $depth = $connection->transactionLevel();

        if ($depth > 0) {
            throw PreconditionFailed::nested($depth);
        }

        $attempt = 0;

        try {
            return $connection->transaction(function (Connection $connection) use ($tenant, $work, &$attempt) {
                $attempt++;

                try {
                    $this->enter($connection, $tenant);

                    return $work($connection);
                } catch (PDOException $e) {
                    $this->noteRetry($e, $tenant, $attempt);

                    throw $e;
                }
            }, self::ATTEMPTS);
        } catch (PDOException $e) {
            throw $this->failure($e, $tenant, $attempt);
        }
    }

    /**
     * Step 2: the settings, then the two assertions, before the closure sees the connection.
     * The values cannot be bound, since SET takes no parameters, and the schema name is quoted
     * by SearchPath, which is the one place the tenant search path is spelled.
     */
    private function enter(Connection $connection, Tenant $tenant): void
    {
        $connection->statement('set local search_path to '.SearchPath::forSchema($tenant->schema_name));
        $connection->statement(sprintf("set local lock_timeout = '%s'", self::LOCK_TIMEOUT));
        $connection->statement(sprintf("set local statement_timeout = '%s'", self::STATEMENT_TIMEOUT));

        $isolation = $connection->scalar("select current_setting('transaction_isolation')");

        if ($isolation !== self::ISOLATION) {
            throw PreconditionFailed::isolation($isolation);
        }

        $schema = $connection->scalar('select current_schema()');

        if ($schema !== $tenant->schema_name) {
            throw PreconditionFailed::schema($tenant->schema_name, $schema);
        }
    }

    /**
     * Records a retry the framework is about to make. The one place the runner sees an error
     * while the transaction is still open, and it touches the log only.
     */
    private function noteRetry(PDOException $e, Tenant $tenant, int $attempt): void
    {
        $state = SqlState::of($e);

        if ($state === null || ! $state->retries() || $attempt >= self::ATTEMPTS) {
            return;
        }

        $this->log->info('Transaction retried', [
            'tenant' => $tenant->slug,
            'sqlstate' => $state->value,
            'attempt' => $attempt,
        ]);
    }

    /**
     * The typed failure for a transaction the framework has rolled back, reported at the level
     * the table sets. A SQLSTATE outside the table is surfaced as it came.
     */
    private function failure(PDOException $e, Tenant $tenant, int $attempts): Throwable
    {
        $state = SqlState::of($e);

        if ($state === null) {
            return $e;
        }

        $failure = $state->failure($e, $tenant->slug, $attempts);

        $this->log->log($state->logLevel(), $failure->getMessage(), [
            'tenant' => $tenant->slug,
            'sqlstate' => $state->value,
            'failure' => $failure::class,
            'attempts' => $attempts,
        ]);

        return $failure;
    }
}
