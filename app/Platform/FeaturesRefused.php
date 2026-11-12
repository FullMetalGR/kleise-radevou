<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

/**
 * The console's Change features refused: a tenant whose plan is billing's, or a removal of
 * staff while more than one member is active. The message is the sentence the detail shows as
 * an error flash; nothing was written or recorded.
 */
final class FeaturesRefused extends RuntimeException
{
    public const string BILLING = 'This account\'s plan belongs to billing.';

    public static function billing(): self
    {
        return new self(self::BILLING);
    }

    public static function because(string $sentence): self
    {
        return new self($sentence);
    }
}
