<?php

declare(strict_types=1);

namespace App\Domain\Money;

use LogicException;

/**
 * Two amounts of different currencies met in one operation. Every price a tenant carries is in
 * the tenant's currency, and a booking's items are summed in that one currency inside the write
 * transaction, so this is a bug in the caller and not a condition to handle: it names both codes
 * and is never answered with false or a converted amount.
 */
final class CurrencyMismatch extends LogicException
{
    private function __construct(
        public readonly Currency $left,
        public readonly Currency $right,
    ) {
        parent::__construct(sprintf('Money in %s cannot be combined with money in %s.', $left->code, $right->code));
    }

    public static function between(Currency $left, Currency $right): self
    {
        return new self($left, $right);
    }
}
