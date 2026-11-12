<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use RuntimeException;

/**
 * The harness could not bring a run to a set of outcomes: a worker outran its budget, or died
 * without reporting, or finished without reaching the checkpoint a test was waiting at. Each
 * message names the worker, because the alternative is a CI job that times out saying nothing.
 */
final class HarnessFailure extends RuntimeException
{
    /**
     * @param  list<string>  $reports  one line per killed worker
     */
    public static function budgetExceeded(float $seconds, array $reports, bool $barrierReleased): self
    {
        return new self(sprintf(
            'Killed for exceeding the %.1fs budget: %s.%s',
            $seconds,
            implode('; ', $reports),
            $barrierReleased ? '' : ' The barrier was never released.',
        ));
    }

    public static function crashed(string $worker, ?int $exitCode, string $stderr): self
    {
        return new self(sprintf(
            'Worker "%s" ended with exit code %s without reporting an outcome.%s',
            $worker,
            $exitCode === null ? 'unknown' : (string) $exitCode,
            $stderr === '' ? '' : "\nstderr: ".$stderr,
        ));
    }

    public static function finishedBeforeCheckpoint(string $worker, string $name, Outcome $outcome): self
    {
        return new self(sprintf(
            'Worker "%s" finished without reaching checkpoint "%s": it %s.',
            $worker,
            $name,
            $outcome->describe(),
        ));
    }

    public static function malformedReport(string $worker, string $line): self
    {
        return new self(sprintf(
            'Worker "%s" wrote to stdout, which carries its reports; a worker prints to stderr instead. The line: %s',
            $worker,
            $line,
        ));
    }
}
