<?php

declare(strict_types=1);

namespace App\Application\Payment;

use RuntimeException;

/**
 * An adapter's refusal of a notification it cannot vouch for: a signature that does not verify
 * with the connection's secret, or a verified body that cannot be a notification. Its message
 * names the reason and never the body, the signature or a secret.
 */
final class NotificationRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
