<?php

declare(strict_types=1);

namespace App\Application;

use InvalidArgumentException;

/**
 * A move that gives a segment the chain does not have to another member: a request counting
 * past the end of the chain or before its start. It stays an invalid argument, since a caller
 * that builds one from what it was shown has miscounted, and is a type of its own so the admin's
 * move can answer it as the request it is rather than as a crash.
 */
final class NoSuchSegment extends InvalidArgumentException
{
    public static function in(int $segments, int $segment): self
    {
        return new self(sprintf('A chain of %d segments has no segment %d to give to another member.', $segments, $segment));
    }
}
