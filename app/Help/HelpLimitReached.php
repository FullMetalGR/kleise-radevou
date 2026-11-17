<?php

declare(strict_types=1);

namespace App\Help;

use RuntimeException;

/**
 * A write refused because its user reached one of the hourly limits; nothing was written.
 */
final class HelpLimitReached extends RuntimeException
{
    public function __construct(public readonly HelpLimit $limit)
    {
        parent::__construct(sprintf('A help write was refused at the hourly %s limit.', $limit->value));
    }
}
