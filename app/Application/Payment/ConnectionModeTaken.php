<?php

declare(strict_types=1);

namespace App\Application\Payment;

use RuntimeException;

/**
 * A provider connected in one mode refuses the other until it is disconnected. It carries the
 * mode the provider is taken in and the provider's name, and the controller words the refusal
 * from them in the request's language, OWN or PLATFORM, keys of lang/{locale}/admin/payments.php
 * with :provider; the message is a developer's.
 */
final class ConnectionModeTaken extends RuntimeException
{
    public const string CODE = 'connection_mode_taken';

    public const string OWN = 'admin/payments.refusals.taken_own';

    public const string PLATFORM = 'admin/payments.refusals.taken_platform';

    private function __construct(public readonly ConnectionMode $mode, public readonly string $name)
    {
        parent::__construct(sprintf('%s is connected in %s mode.', $name, $mode->value));
    }

    public static function by(PaymentConnection $current, string $name): self
    {
        return new self($current->mode, $name);
    }
}
