<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;
use Throwable;

/**
 * A verified event the webhook recorded and could not finish handling on our side, left
 * unmarked so the provider's retry, the only second chance it gets, handles it again. The
 * webhook has already logged it once with the event in context; the framework reports it as
 * well, with the cause it wraps, so the trace, the file and the line are kept under the
 * framework's own entry, and the route answers it as the error envelope.
 */
final class NoticeUnhandled extends RuntimeException
{
    public static function unresolved(): self
    {
        return new self('The subscription sync could not resolve what the provider answered.');
    }

    public static function contended(): self
    {
        return new self('The subscriptions row kept moving while the event was handled.');
    }

    public static function after(Throwable $cause): self
    {
        return new self('The billing event could not be handled.', 0, $cause);
    }
}
