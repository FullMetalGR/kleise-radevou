<?php

declare(strict_types=1);

namespace App\Tenancy;

use InvalidArgumentException;

final class InvalidSlug extends InvalidArgumentException
{
    private function __construct(public readonly SlugProblem $problem)
    {
        parent::__construct($problem->detail());
    }

    public static function because(SlugProblem $problem): self
    {
        return new self($problem);
    }
}
