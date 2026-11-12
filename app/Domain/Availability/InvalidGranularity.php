<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use InvalidArgumentException;

/**
 * A number of minutes that does not divide sixty. The one rule a granularity has, and the
 * only reason one is refused.
 */
final class InvalidGranularity extends InvalidArgumentException
{
    private function __construct(public readonly int $input)
    {
        parent::__construct(sprintf('A granularity is a number of minutes dividing sixty, not %d.', $input));
    }

    public static function minutes(int $input): self
    {
        return new self($input);
    }
}
