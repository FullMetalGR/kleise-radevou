<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use RuntimeException;

/**
 * The parent's handle on one worker process: the pipes, what the worker has reported so far,
 * and its deadline. Reports arrive on the worker's stdout, one line each; its stderr is kept
 * for diagnostics. A PHP child can reach only the three standard descriptors, so stdout is the
 * report channel and a closure that prints to it fails its run rather than corrupting it.
 */
final class WorkerProcess
{
    private const int REPORTS = 1;

    private const int STDERR = 2;

    private const int CHUNK = 65536;

    private const int TAIL = 4000;

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $stderr = '';

    private string $partialReport = '';

    public readonly float $deadline;

    private bool $ready = false;

    /** @var list<int> */
    private array $backendPids = [];

    /** @var array<string, int> checkpoint => reports of it not yet awaited */
    private array $reached = [];

    private ?Outcome $outcome = null;

    private bool $killed = false;

    private bool $reaped = false;

    private ?int $exitCode = null;

    private string $lastReport = 'spawned';

    public function __construct(
        public readonly string $name,
        string $payload,
        float $budget,
        string $script,
        string $directory,
    ) {
        $descriptors = [
            0 => ['pipe', 'r'],
            self::REPORTS => ['pipe', 'w'],
            self::STDERR => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', $script],
            $descriptors,
            $pipes,
            $directory,
            getenv(),
        );

        if (! is_resource($process)) {
            throw new RuntimeException(sprintf('Worker "%s" could not be started.', $this->name));
        }

        $this->process = $process;
        $this->deadline = microtime(true) + $budget;

        foreach ($pipes as $descriptor => $pipe) {
            if (! is_resource($pipe)) {
                throw new RuntimeException(sprintf('Worker "%s" was started without its pipes.', $this->name));
            }

            if ($descriptor === 0) {
                fwrite($pipe, $payload);
                fclose($pipe);

                continue;
            }

            stream_set_blocking($pipe, false);
            $this->pipes[$descriptor] = $pipe;
        }
    }

    /**
     * @return list<resource>
     */
    public function openPipes(): array
    {
        return array_values($this->pipes);
    }

    /**
     * @param  resource  $pipe
     */
    public function owns($pipe): bool
    {
        return in_array($pipe, $this->pipes, true);
    }

    /**
     * Reads what a pipe has, or closes it at end of file; the process is reaped once every pipe
     * has closed, which is after it has exited.
     *
     * @param  resource  $pipe
     */
    public function readFrom($pipe): void
    {
        $descriptor = array_search($pipe, $this->pipes, true);

        if ($descriptor === false) {
            return;
        }

        $data = fread($pipe, self::CHUNK);

        if ($data === false || $data === '') {
            if (feof($pipe)) {
                fclose($pipe);
                unset($this->pipes[$descriptor]);
                $this->reapIfDone();
            }

            return;
        }

        if ($descriptor === self::STDERR) {
            $this->stderr .= $data;
        } else {
            $this->consumeReports($data);
        }
    }

    public function isRunning(): bool
    {
        return ! $this->reaped;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function isOverdue(float $now): bool
    {
        return ! $this->reaped && ! $this->killed && $now > $this->deadline;
    }

    /**
     * @return list<int>
     */
    public function backendPids(): array
    {
        return $this->backendPids;
    }

    public function hasReached(string $checkpoint): bool
    {
        return ($this->reached[$checkpoint] ?? 0) > 0;
    }

    /**
     * Consumes the earliest unawaited report of the checkpoint; false when there is none.
     */
    public function takeCheckpoint(string $checkpoint): bool
    {
        if (! $this->hasReached($checkpoint)) {
            return false;
        }

        $this->reached[$checkpoint]--;

        return true;
    }

    public function outcome(): ?Outcome
    {
        return $this->outcome;
    }

    public function wasKilled(): bool
    {
        return $this->killed;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function stderrTail(): string
    {
        return trim(substr($this->stderr, -self::TAIL));
    }

    public function describeForFailure(): string
    {
        return sprintf('"%s" (last reported: %s)', $this->name, $this->lastReport);
    }

    /**
     * Ends the process now. What is left in the pipes is dropped; the process is reaped so the
     * handle never waits on it again.
     */
    public function kill(): void
    {
        if ($this->reaped) {
            return;
        }

        proc_terminate($this->process, 9);
        $this->killed = true;

        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        $this->pipes = [];
        $this->reapIfDone();
    }

    private function reapIfDone(): void
    {
        if ($this->reaped || $this->pipes !== []) {
            return;
        }

        $this->exitCode = proc_close($this->process);
        $this->reaped = true;
    }

    private function consumeReports(string $data): void
    {
        $this->partialReport .= $data;

        while (($newline = strpos($this->partialReport, "\n")) !== false) {
            $line = substr($this->partialReport, 0, $newline);
            $this->partialReport = substr($this->partialReport, $newline + 1);
            $this->report($line);
        }
    }

    private function report(string $line): void
    {
        [$kind, $rest] = array_pad(explode(' ', $line, 2), 2, '');

        switch ($kind) {
            case 'ready':
                $pids = array_map(intval(...), explode(' ', $rest));

                if (count($pids) !== 2 || min($pids) <= 0) {
                    throw HarnessFailure::malformedReport($this->name, $line);
                }

                $this->backendPids = $pids;
                $this->ready = true;
                break;
            case 'checkpoint':
                if ($rest === '') {
                    throw HarnessFailure::malformedReport($this->name, $line);
                }

                $this->reached[$rest] = ($this->reached[$rest] ?? 0) + 1;
                break;
            case 'outcome':
                $this->outcome = Outcome::fromWire($this->name, $rest);
                break;
            default:
                throw HarnessFailure::malformedReport($this->name, $line);
        }

        $this->lastReport = $kind === 'checkpoint' ? 'checkpoint '.$rest : $kind;
    }
}
