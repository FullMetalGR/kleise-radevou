<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Clock;
use DateTimeImmutable;
use RuntimeException;

/**
 * A clock that cannot answer, which is any fault below a listener's dispatch: under the sync
 * driver the job runs inside that call, so what it throws is what the listener would throw.
 * The jobs that stamp a calendar file or place a reminder read the clock; a business notice
 * reads no clock at all, which is what lets a test fail exactly one of two listeners on one
 * record.
 */
final readonly class HaltedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        throw new RuntimeException('the clock is not there');
    }
}
