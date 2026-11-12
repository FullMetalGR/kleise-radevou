<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * A subscription snapshot whose line items cannot be resolved to features: a line naming no
 * price, or a quantity below nought. The sync that meets it changes nothing on the tenant row
 * and logs.
 */
final class MalformedSnapshot extends RuntimeException
{
    /**
     * @param  int  $line  counted from one, as a person reading the subscription counts it
     */
    public static function line(int $line): self
    {
        return new self(sprintf('Line %d of the subscription names no price or a quantity below nought.', $line));
    }
}
