<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use App\Application\Checkpoint;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use RuntimeException;
use Throwable;

/**
 * What runs inside a worker process: boot the application against the test's database, open
 * the connection under test and the control connection, install the checkpoint observer,
 * report ready, wait at the barrier, run the closure, report the outcome.
 *
 * The closure's own exception is an outcome and exits 0. Anything failing around it goes to
 * stderr with exit 1, which the parent reports as a crash. Reports go to stdout, which is why
 * the parent starts this process with display_errors pointed at stderr.
 */
final class WorkerRuntime
{
    public static function main(string $basePath): int
    {
        try {
            $payload = Payload::fromWire((string) stream_get_contents(STDIN));
            $reports = STDOUT;
            $app = self::boot($basePath, $payload);
            $backendPid = self::connect($app, $payload);
            $control = new ControlConnection($payload->config);
            self::observeCheckpoints($payload, $control, $reports);

            fwrite($reports, sprintf("ready %d %d\n", $backendPid, $control->backendPid()));

            $barrier = AdvisoryKey::barrier($payload->nonce);
            $control->lockShared($barrier);
            $control->unlockShared($barrier);

            fwrite($reports, 'outcome '.self::wire($payload, self::execute($payload))."\n");
            fflush($reports);

            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");

            return 1;
        }
    }

    private static function boot(string $basePath, Payload $payload): Application
    {
        $app = require $basePath.'/bootstrap/app.php';

        if (! $app instanceof Application) {
            throw new RuntimeException('bootstrap/app.php did not return the application.');
        }

        $app->make(Kernel::class)->bootstrap();

        $config = $app->make(Repository::class);
        $config->set('database.default', $payload->connection);
        $config->set('database.connections.'.$payload->connection, $payload->config);

        return $app;
    }

    /**
     * Opens the connection the closure will use and confirms it reached the test's database.
     *
     * @return int the backend pid of that connection
     */
    private static function connect(Application $app, Payload $payload): int
    {
        $connection = $app->make(DatabaseManager::class)->connection($payload->connection);
        $database = $connection->scalar('select current_database()');

        if ($database !== $payload->database) {
            throw new RuntimeException(sprintf(
                'The worker connected to database %s but the test runs against "%s".',
                is_string($database) ? '"'.$database.'"' : gettype($database),
                $payload->database,
            ));
        }

        $pid = $connection->scalar('select pg_backend_pid()');

        if (! is_int($pid)) {
            throw new RuntimeException('The server reported no backend pid.');
        }

        return $pid;
    }

    /**
     * @param  resource  $reports
     */
    private static function observeCheckpoints(Payload $payload, ControlConnection $control, $reports): void
    {
        Checkpoint::observe(function (string $name) use ($payload, $control, $reports): void {
            if (! in_array($name, $payload->held, true)) {
                return;
            }

            fwrite($reports, 'checkpoint '.$name."\n");

            $key = AdvisoryKey::checkpoint($payload->nonce, $payload->index, $name);
            $control->lockShared($key);
            $control->unlockShared($key);
        });
    }

    private static function execute(Payload $payload): Outcome
    {
        try {
            return Outcome::ofReturn($payload->worker, ($payload->closure())());
        } catch (Throwable $exception) {
            return Outcome::ofThrowable($payload->worker, $exception);
        }
    }

    /**
     * A returned value that does not serialize is reported as the exception serializing it
     * raised, so the test learns what it returned rather than reading a crash.
     */
    private static function wire(Payload $payload, Outcome $outcome): string
    {
        try {
            return $outcome->toWire();
        } catch (Throwable $exception) {
            return Outcome::ofThrowable($payload->worker, $exception)->toWire();
        }
    }
}
