<?php

declare(strict_types=1);

namespace App\Application\Payment\Stripe;

use RuntimeException;
use SensitiveParameter;

/**
 * A Stripe operation that did not happen, worded: the operation, and Stripe's own reason where
 * it gave one, with every secret the connection holds and any key-shaped token struck out. It
 * wraps no exception, since a client's exception carries the request and its headers. The
 * caller that asked reports it once by its class.
 */
final class StripeFailed extends RuntimeException
{
    /**
     * @param  list<string>  $secrets  the connection's secret values, struck from the reason
     */
    public static function because(string $operation, string $reason, #[SensitiveParameter] array $secrets = []): self
    {
        // Struck in place, so the trace this exception captures holds the reason struck too.
        $reason = self::struck($reason, $secrets);

        return new self(sprintf('Stripe did not %s: %s', $operation, $reason));
    }

    /**
     * Stripe's reason with every secret the connection holds and any key-shaped token struck
     * out, as because() words it; the connection test's value for its line's :reason reads the
     * same strike.
     *
     * @param  list<string>  $secrets
     */
    public static function struck(string $reason, #[SensitiveParameter] array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $reason = str_replace($secret, '[secret]', $reason);
            }
        }

        return (string) preg_replace('/\b(sk|rk|pk|whsec)_[A-Za-z0-9_*]+/', '[key]', $reason);
    }
}
