<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Illuminate\Database\Connectors\PostgresConnector;
use LogicException;
use PDO;

/**
 * The harness's own session on the database, apart from the connection the code under test
 * uses. Every wait the harness performs is a lock taken here, so no wait enters the
 * transaction under test and a SET LOCAL lock_timeout there cannot cut one short. The locks
 * are session-level and vanish with the session, so closing this releases everything.
 */
final class ControlConnection
{
    private ?PDO $pdo;

    /**
     * @param  array<string, mixed>  $config  the resolved configuration of the connection under test
     */
    public function __construct(array $config)
    {
        $this->pdo = (new PostgresConnector)->connect($config);
    }

    public function lock(AdvisoryKey $key): void
    {
        $this->call('pg_advisory_lock', $key);
    }

    public function unlock(AdvisoryKey $key): void
    {
        $this->call('pg_advisory_unlock', $key);
    }

    public function lockShared(AdvisoryKey $key): void
    {
        $this->call('pg_advisory_lock_shared', $key);
    }

    public function unlockShared(AdvisoryKey $key): void
    {
        $this->call('pg_advisory_unlock_shared', $key);
    }

    public function backendPid(): int
    {
        $statement = $this->pdo()->query('select pg_backend_pid()');
        $pid = $statement === false ? null : $statement->fetchColumn();

        if (! is_int($pid)) {
            throw new LogicException('The server reported no backend pid.');
        }

        return $pid;
    }

    /**
     * Ends another session and waits until it is gone, so the locks it held are released by the
     * time this returns.
     */
    public function terminateBackend(int $pid): void
    {
        $statement = $this->pdo()->prepare('select pg_terminate_backend(?::int, 5000)');
        $statement->execute([$pid]);
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function call(string $function, AdvisoryKey $key): void
    {
        $statement = $this->pdo()->prepare(sprintf('select %s(?::int, ?::int)', $function));
        $statement->execute([$key->class, $key->key]);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new LogicException('The control connection is closed.');
        }

        return $this->pdo;
    }
}
