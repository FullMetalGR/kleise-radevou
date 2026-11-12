<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

/**
 * A suspend or a resume the tenant's status refuses: the message is the sentence the console
 * shows the operator as it stands, and names nothing but the status's reason.
 */
final class SuspensionRefused extends RuntimeException
{
    public static function because(string $sentence): self
    {
        return new self($sentence);
    }
}
