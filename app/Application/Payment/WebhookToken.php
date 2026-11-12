<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * The opaque token of an own-mode connection's webhook URL: random, URL-safe, minted when
 * credentials are first saved for a provider and again when the tenant rotates it. A routing
 * name and not a secret: the provider's signature is what vouches for a notification.
 */
final class WebhookToken
{
    /** Bytes of randomness, 192 bits, spelled as 32 characters. */
    private const int BYTES = 24;

    public static function mint(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }
}
