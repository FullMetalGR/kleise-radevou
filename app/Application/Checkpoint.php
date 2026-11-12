<?php

declare(strict_types=1);

namespace App\Application;

use Closure;

/**
 * A named point that production code passes through and a concurrency test can stop it at.
 *
 * Outside the test harness nothing observes, and reach() is one null check. Inside a harness
 * worker the runtime installs an observer that blocks on the name until the test releases it,
 * which is how a test interleaves two transactions at a chosen step. The seam is a name in and
 * nothing out: the caller cannot learn whether it was stopped, so nothing can branch on it.
 */
final class Checkpoint
{
    /** @var (Closure(string): void)|null */
    private static ?Closure $observer = null;

    public static function reach(string $name): void
    {
        if (self::$observer === null) {
            return;
        }

        (self::$observer)($name);
    }

    /**
     * @param  (Closure(string): void)|null  $observer
     */
    public static function observe(?Closure $observer): void
    {
        self::$observer = $observer;
    }
}
