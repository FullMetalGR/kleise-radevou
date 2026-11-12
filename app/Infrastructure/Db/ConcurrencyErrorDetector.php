<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Throwable;

/**
 * What the framework's transaction retry consults, bound over its own detector in the service
 * provider. The framework's lists 40001 by code and finds 40P01 only through the English text
 * "deadlock detected", case-sensitively, so on a cluster with a translated lc_messages deadlock
 * retry is silently off. This one reads the SQLSTATE and nothing else, and it leaves 55P03 out:
 * a lock timeout is surfaced as busy, never blind-retried.
 */
final class ConcurrencyErrorDetector implements ConcurrencyErrorDetectorContract
{
    public function causedByConcurrencyError(Throwable $e): bool
    {
        return SqlState::of($e)?->retries() === true;
    }
}
