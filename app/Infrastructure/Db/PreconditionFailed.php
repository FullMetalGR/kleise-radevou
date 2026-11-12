<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use RuntimeException;

/**
 * One of the preconditions that the runner asserts itself was false: P1 before the
 * transaction begins, P4 and P5 inside it, before the closure runs. Each one, unasserted,
 * produces a double booking or a data leak that no error would ever report.
 */
final class PreconditionFailed extends RuntimeException
{
    private function __construct(public readonly string $precondition, string $message)
    {
        parent::__construct($message);
    }

    public static function nested(int $depth): self
    {
        return new self('P1', sprintf(
            'Precondition P1: the booking transaction must be top-level, but the connection is already %d deep. A nested transaction is a savepoint, and a savepoint never retries.',
            $depth,
        ));
    }

    public static function isolation(mixed $found): self
    {
        return new self('P4', sprintf(
            'Precondition P4: the transaction must run at read committed, but it runs at %s. At repeatable read the protocol double-books silently.',
            self::describe($found),
        ));
    }

    public static function schema(string $expected, mixed $found): self
    {
        return new self('P5', sprintf(
            'Precondition P5: current_schema() must be the tenant schema "%s", but it is %s. A connection on another schema is a data leak.',
            $expected,
            self::describe($found),
        ));
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? '"'.$value.'"' : get_debug_type($value);
    }
}
