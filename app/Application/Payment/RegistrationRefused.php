<?php

declare(strict_types=1);

namespace App\Application\Payment;

use LogicException;

/**
 * A provider the registry would not take: a descriptor that leaves out something a provider
 * must declare, a slug already taken or one a route of the platform's holds, or a class that
 * is no adapter. Raised where the provider is registered, so a half-declared provider never
 * reaches a screen or a booking.
 */
final class RegistrationRefused extends LogicException
{
    public static function missing(string $slug, string $field): self
    {
        return new self(sprintf('The payment provider "%s" does not declare %s.', $slug, $field));
    }

    public static function taken(string $slug): self
    {
        return new self(sprintf('A payment provider is already registered as "%s".', $slug));
    }

    public static function reserved(string $slug): self
    {
        return new self(sprintf('The slug "%s" is the platform\'s %s webhook; no payment provider may be registered as it.', $slug, $slug));
    }

    public static function notAnAdapter(string $class): self
    {
        return new self(sprintf('The configured payment provider %s is not a payment adapter.', $class));
    }
}
