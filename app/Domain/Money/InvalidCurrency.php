<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * A currency code that is not exactly three uppercase ASCII letters. The one shape rule, and
 * the only reason a code is refused: the domain has no standing to judge which codes a tenant
 * may charge in.
 */
final class InvalidCurrency extends InvalidArgumentException
{
    private function __construct(public readonly string $input)
    {
        parent::__construct(sprintf('A currency code is exactly three uppercase ASCII letters, not "%s".', $input));
    }

    public static function code(string $input): self
    {
        return new self($input);
    }
}
