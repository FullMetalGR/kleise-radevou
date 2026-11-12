<?php

declare(strict_types=1);

namespace App\Platform;

use RuntimeException;

/**
 * A reopen the tenant's row refuses: the message is the sentence the console shows the operator
 * as it stands, and names nothing but the row's reason. Nothing was written and nothing was
 * sent.
 */
final class ReopenRefused extends RuntimeException
{
    public static function because(string $sentence): self
    {
        return new self($sentence);
    }
}
