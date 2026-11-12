<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use RuntimeException;
use Throwable;

/**
 * A verified payment notification whose handling did not finish. The webhook answers the
 * envelope's 500 so the provider delivers it again, the event stays unmarked for that retry,
 * and the framework reports this with the cause's trace under it.
 */
final class NotificationUnhandled extends RuntimeException
{
    public static function after(string $provider, string $eventId, Throwable $cause): self
    {
        return new self(sprintf('The %s payment notification %s could not be handled.', $provider, $eventId), 0, $cause);
    }
}
