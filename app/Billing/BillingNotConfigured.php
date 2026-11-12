<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

/**
 * The billing provider was asked for something its configuration cannot answer. Raised at first
 * use and never at boot, so an application without billing keys still serves every tenant.
 */
final class BillingNotConfigured extends RuntimeException
{
    public const string NO_SECRET_KEY = 'The billing provider has no secret key: set BILLING_SECRET_KEY in the environment.';

    public const string NO_WEBHOOK_SECRET = 'The billing provider has no webhook signing secret: set BILLING_WEBHOOK_SECRET in the environment.';

    /**
     * A question for the provider's API with no secret key to ask it with.
     */
    public static function noSecretKey(): self
    {
        return new self(self::NO_SECRET_KEY);
    }

    /**
     * A notification to verify with no signing secret to verify it against.
     */
    public static function noWebhookSecret(): self
    {
        return new self(self::NO_WEBHOOK_SECRET);
    }

    /**
     * The configuration names a class that is not a billing provider, BILLING_PROVIDER.
     */
    public static function notAPort(string $class): self
    {
        return new self(sprintf('BILLING_PROVIDER names %s, which is not a billing provider.', $class));
    }
}
