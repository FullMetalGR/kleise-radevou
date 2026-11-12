<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * The billing provider could not answer a question the port asked: the connection failed, the
 * provider refused the request, or its answer lacked what the port reads. The sentence names the
 * operation and the provider's own code and message where it gave them, and never a secret, so
 * one log line locates it.
 */
final class BillingProviderFailed extends RuntimeException
{
    public static function unreachable(string $operation, string $reason): self
    {
        return new self(sprintf('The billing provider could not be reached to %s: %s', $operation, $reason));
    }

    public static function refused(string $operation, int $status, string $code, string $message): self
    {
        return new self(sprintf('The billing provider refused to %s with status %d, code %s: %s', $operation, $status, $code, $message));
    }

    public static function unreadable(string $operation, string $field): self
    {
        return new self(sprintf('The billing provider answered a request to %s without a readable %s.', $operation, $field));
    }
}
