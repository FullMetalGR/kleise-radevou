<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use LogicException;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * N named closures, each run in its own process on its own database connection, released from
 * a barrier together, with their outcomes read back by the test.
 *
 * Processes rather than connections, because a process blocked on a row lock can never release
 * a lock another of its own connections holds. Fresh processes rather than forks, because a
 * forked child inherits the parent's connection socket and its exit sends the server a
 * terminate on it, which is the session holding the barrier. So each worker is a new php
 * process that boots the application, opens its own connections, and receives its closure
 * serialized on stdin. That is why a worker closure may not use $this, self or static, may
 * capture only values that serialize, and may call only autoloaded code: a function declared in
 * a test file or in tests/Pest.php does not exist in the worker.
 *
 * The barrier is an advisory lock the parent holds before any worker starts; a worker reports
 * ready and then blocks asking for it in shared mode, and release() is one unlock that wakes
 * every waiter. A held checkpoint is the same thing per worker and name. Every wait the parent
 * itself performs is a select() on the worker pipes bounded by the earliest deadline; nothing
 * here sleeps. A worker past its budget is killed, its database sessions are terminated so the
 * locks they held go with them, and the test fails naming it.
 */
final class ConcurrentRun
{
    private const string SCRIPT = __DIR__.'/worker.php';

    private const float DEFAULT_BUDGET = 10.0;

    private const string NAME_PATTERN = '/^[A-Za-z0-9_.-]+$/';

    /** @var array<string, Closure> */
    private array $closures = [];

    /** @var list<string> */
    private array $held = [];

    private float $budget = self::DEFAULT_BUDGET;

    private ?ControlConnection $control = null;

    /** @var array<string, WorkerProcess> */
    private array $workers = [];

    private int $nonce = 0;

    private bool $released = false;

    /** @var array<string, array<string, AdvisoryKey>> checkpoint => worker => the lock still held */
    private array $heldKeys = [];

    /** @var array<int, WeakReference<self>> */
    private static array $live = [];

    private function __construct() {}

    /**
     * @param  array<string, Closure>  $workers  named closures; outcomes come back in this order
     */
    public static function of(array $workers): self
    {
        if ($workers === []) {
            throw new LogicException('A run needs at least one worker.');
        }

        $run = new self;

        foreach ($workers as $name => $closure) {
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new LogicException('Workers are named with letters, digits, dots, dashes and underscores.');
            }

            $run->closures[$name] = self::portable($name, $closure);
        }

        return $run;
    }

    /**
     * Checkpoint names to hold for every worker. A worker reaching one blocks until the test
     * releases it; a name not held here returns at once.
     */
    public function holding(string ...$names): self
    {
        foreach ($names as $name) {
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new LogicException('Checkpoints are named with letters, digits, dots, dashes and underscores.');
            }
        }

        $this->held = array_values(array_unique([...$this->held, ...$names]));

        return $this;
    }

    /**
     * Wall-clock seconds each worker gets, from the moment its process starts until its outcome
     * is read. The default is ten.
     */
    public function budget(float $seconds): self
    {
        if ($seconds <= 0.0) {
            throw new LogicException('A budget is a positive number of seconds.');
        }

        $this->budget = $seconds;

        return $this;
    }

    /**
     * Takes the barrier and every held checkpoint, starts the workers, and returns once each has
     * booted, opened its connections and is about to block on the barrier.
     */
    public function start(): self
    {
        if ($this->control !== null) {
            throw new LogicException('The run has already started.');
        }

        $connection = self::connectionUnderTest();
        $this->control = new ControlConnection($connection['config']);
        $this->nonce = random_int(1, 0x7FFFFFFF);
        $this->control->lock(AdvisoryKey::barrier($this->nonce));
        self::$live[spl_object_id($this)] = WeakReference::create($this);

        $index = 0;

        foreach ($this->closures as $name => $closure) {
            foreach ($this->held as $checkpoint) {
                $key = AdvisoryKey::checkpoint($this->nonce, $index, $checkpoint);
                $this->control->lock($key);
                $this->heldKeys[$checkpoint][$name] = $key;
            }

            $payload = new Payload(
                worker: $name,
                index: $index,
                nonce: $this->nonce,
                held: $this->held,
                closure: new UnsignedSerializableClosure($closure),
                connection: $connection['name'],
                config: $connection['config'],
                database: $connection['database'],
            );

            $this->workers[$name] = new WorkerProcess($name, $payload->toWire(), $this->budget, self::SCRIPT, base_path());
            $index++;
        }

        $this->pump(fn (): bool => $this->allReady());

        foreach ($this->workers as $worker) {
            if (! $worker->isReady()) {
                $this->failFor($worker);
            }
        }

        return $this;
    }

    /**
     * Opens the barrier: one unlock, and every worker waiting on it runs.
     */
    public function release(): void
    {
        $control = $this->started();

        if ($this->released) {
            throw new LogicException('The barrier has already been released.');
        }

        $control->unlock(AdvisoryKey::barrier($this->nonce));
        $this->released = true;
    }

    /**
     * Blocks until the worker reports it has reached the checkpoint, at which point it is
     * waiting on the test. Each call consumes one report, so a worker passing the same name
     * twice can be awaited twice.
     */
    public function awaitCheckpoint(string $worker, string $name): void
    {
        $this->started();
        $process = $this->worker($worker);

        $this->pump(fn (): bool => $process->hasReached($name) || ! $process->isRunning());

        if ($process->takeCheckpoint($name)) {
            return;
        }

        $outcome = $process->outcome();

        if ($outcome !== null && ! $process->wasKilled()) {
            throw HarnessFailure::finishedBeforeCheckpoint($worker, $name, $outcome);
        }

        $this->failFor($process);
    }

    /**
     * Lets every worker, or one named worker, past the checkpoint. Releasing before a worker
     * arrives is allowed: it then passes through without stopping.
     */
    public function releaseCheckpoint(string $name, ?string $worker = null): void
    {
        $control = $this->started();

        if (! in_array($name, $this->held, true)) {
            throw new LogicException(sprintf('Checkpoint "%s" is not held by this run.', $name));
        }

        $keys = $this->heldKeys[$name] ?? [];

        if ($worker !== null) {
            $this->worker($worker);
            $keys = array_intersect_key($keys, [$worker => true]);
        }

        if ($keys === []) {
            throw new LogicException(sprintf(
                'Checkpoint "%s" has already been released%s.',
                $name,
                $worker === null ? '' : ' for worker "'.$worker.'"',
            ));
        }

        foreach ($keys as $workerName => $key) {
            $control->unlock($key);
            unset($this->heldKeys[$name][$workerName]);
        }
    }

    /**
     * Whether the worker's outcome has arrived, after reading whatever the pipes hold.
     */
    public function hasOutcome(string $worker): bool
    {
        $this->started();
        $process = $this->worker($worker);
        $this->poll();

        return $process->outcome() !== null;
    }

    /**
     * Blocks until this one worker's outcome arrives, leaving the others running.
     */
    public function outcomeOf(string $worker): Outcome
    {
        $this->started();
        $process = $this->worker($worker);

        $this->pump(fn (): bool => $process->outcome() !== null || ! $process->isRunning());

        $outcome = $process->outcome();

        if ($outcome === null || $process->wasKilled()) {
            $this->failFor($process);
        }

        return $outcome;
    }

    /**
     * Blocks until every worker has finished, then returns every outcome by worker name. A
     * worker killed for its budget, or one that died without reporting, fails the run instead.
     *
     * @return array<string, Outcome>
     */
    public function wait(): array
    {
        $this->started();
        $this->pump(fn (): bool => $this->running() === []);

        $outcomes = [];
        $killed = [];
        $crashed = null;

        foreach ($this->workers as $name => $process) {
            $outcome = $process->outcome();

            if ($process->wasKilled()) {
                $killed[] = $process->describeForFailure();
            } elseif ($outcome === null || $process->exitCode() !== 0) {
                $crashed ??= HarnessFailure::crashed($name, $process->exitCode(), $process->stderrTail());
            } else {
                $outcomes[$name] = $outcome;
            }
        }

        $this->abandon();

        if ($killed !== []) {
            throw HarnessFailure::budgetExceeded($this->budget, $killed, $this->released);
        }

        if ($crashed !== null) {
            throw $crashed;
        }

        return $outcomes;
    }

    /**
     * The common case: start, release, wait.
     *
     * @return array<string, Outcome>
     */
    public function run(): array
    {
        $this->start();
        $this->release();

        return $this->wait();
    }

    /**
     * Kills every worker of every run still live, so a test that failed mid-run leaves no
     * process behind blocking on a lock this process holds.
     */
    public static function abandonAll(): void
    {
        foreach (self::$live as $reference) {
            $reference->get()?->abandon();
        }

        self::$live = [];
    }

    public function __destruct()
    {
        $this->abandon();
    }

    private function abandon(): void
    {
        unset(self::$live[spl_object_id($this)]);

        foreach ($this->workers as $process) {
            if ($process->isRunning()) {
                $this->kill($process);
            }
        }

        $this->control?->close();
        $this->control = null;
    }

    private function kill(WorkerProcess $process): void
    {
        $process->kill();

        try {
            foreach ($process->backendPids() as $pid) {
                $this->control?->terminateBackend($pid);
            }
        } catch (Throwable) {
            // The control connection is gone too; the server reaps the sessions itself.
        }
    }

    /**
     * Reads the pipes until the condition holds or no worker is running, killing any worker
     * that passes its deadline on the way.
     *
     * @param  Closure(): bool  $satisfied
     */
    private function pump(Closure $satisfied): void
    {
        while (! $satisfied()) {
            $running = $this->running();

            if ($running === []) {
                return;
            }

            $deadline = min(array_map(fn (WorkerProcess $process): float => $process->deadline, $running));
            $this->pass(max(0.0, $deadline - microtime(true)), $running);

            $now = microtime(true);

            foreach ($running as $process) {
                if ($process->isOverdue($now)) {
                    $this->kill($process);
                }
            }
        }
    }

    private function poll(): void
    {
        $running = $this->running();

        if ($running !== []) {
            $this->pass(0.0, $running);
        }
    }

    /**
     * One select() over every open pipe, waiting at most the timeout, then one read per pipe
     * that has something.
     *
     * @param  array<string, WorkerProcess>  $running
     */
    private function pass(float $timeout, array $running): void
    {
        $read = [];

        foreach ($running as $process) {
            foreach ($process->openPipes() as $pipe) {
                $read[] = $pipe;
            }
        }

        if ($read === []) {
            return;
        }

        $write = null;
        $except = null;
        $seconds = (int) floor($timeout);
        $microseconds = (int) round(($timeout - $seconds) * 1e6);

        if (stream_select($read, $write, $except, $seconds, $microseconds) === false) {
            throw new RuntimeException('Waiting on the worker pipes failed.');
        }

        foreach ($read as $pipe) {
            foreach ($running as $process) {
                if ($process->owns($pipe)) {
                    $process->readFrom($pipe);
                    break;
                }
            }
        }
    }

    /**
     * @return array<string, WorkerProcess>
     */
    private function running(): array
    {
        return array_filter($this->workers, fn (WorkerProcess $process): bool => $process->isRunning());
    }

    private function allReady(): bool
    {
        foreach ($this->workers as $process) {
            if (! $process->isReady()) {
                return false;
            }
        }

        return true;
    }

    private function failFor(WorkerProcess $process): never
    {
        if ($process->wasKilled()) {
            throw HarnessFailure::budgetExceeded($this->budget, [$process->describeForFailure()], $this->released);
        }

        throw HarnessFailure::crashed($process->name, $process->exitCode(), $process->stderrTail());
    }

    private function started(): ControlConnection
    {
        if ($this->control === null) {
            throw new LogicException('The run has not started, or has already finished.');
        }

        return $this->control;
    }

    private function worker(string $name): WorkerProcess
    {
        return $this->workers[$name] ?? throw new LogicException(sprintf('There is no worker named "%s".', $name));
    }

    /**
     * A closure as it will be in the worker: no bound object and no class scope, since neither
     * the test case instance nor the test class exist there.
     */
    private static function portable(string $name, Closure $closure): Closure
    {
        $reflection = new ReflectionClosure($closure);

        if ($reflection->isBindingRequired()) {
            throw new LogicException(sprintf('Worker "%s" uses $this, which does not exist in the worker process.', $name));
        }

        if ($reflection->isScopeRequired()) {
            throw new LogicException(sprintf('Worker "%s" uses self, static or parent, which do not exist in the worker process.', $name));
        }

        return Closure::bind($closure, null, null)
            ?? throw new LogicException(sprintf('Worker "%s" could not be unbound from the test case.', $name));
    }

    /**
     * @return array{name: string, config: array<string, mixed>, database: string}
     */
    private static function connectionUnderTest(): array
    {
        $connection = DB::connection();
        $config = $connection->getConfig();

        if (! is_array($config)) {
            throw new LogicException('The connection under test has no configuration to hand to the workers.');
        }

        /** @var array<string, mixed> $config */
        return [
            'name' => $connection->getName() ?? 'pgsql',
            'config' => $config,
            'database' => $connection->getDatabaseName(),
        ];
    }
}
