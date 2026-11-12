<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock that answers one instant, so a test decides what now is. Spelled in whatever zone
 * the test finds clearest; the use cases read the position on the timeline.
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $text, string $zone = 'UTC'): self
    {
        return new self(new DateTimeImmutable($text, new DateTimeZone($zone)));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
