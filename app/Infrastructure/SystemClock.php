<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The server's clock, in UTC to the microsecond. The one Clock bound outside a test.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
