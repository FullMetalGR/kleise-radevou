<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeImmutable;

/**
 * The current instant, for a use case that must know it: the availability query drops starts
 * inside the lead time and derives the tenant's today from it. A port rather than a call, so
 * that a test hands in an instant and the answer is the same on every run, and so that nothing
 * under Domain ever reads a clock. Whatever zone the instant is spelled in, only its position on
 * the timeline is read.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
