<?php

declare(strict_types=1);

namespace App\Application\Payment;

use RuntimeException;

/**
 * A platform connection's onboarding asked for while the platform's own keys for its provider
 * are unset: the connection stands as it was and nothing is asked of the provider, since no
 * call can be made without the keys. It is the deployment's to fix and not the tenant's, and it
 * is not the 404 of a connection gone, so it is refused in its own words: DETAIL, a key of
 * lang/{locale}/admin/payments.php the controller words in the request's language. The message
 * is a developer's.
 */
final class PlatformUnavailable extends RuntimeException
{
    public const string CODE = 'platform_unavailable';

    public const string DETAIL = 'admin/payments.refusals.unavailable';

    public static function make(): self
    {
        return new self('The platform\'s keys for the provider are unset.');
    }
}
