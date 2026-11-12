<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * A tenant's start interval, set in a test's arrangement: the one place a suite writes
 * settings.start_interval_min, so every file speaks of it the same way.
 *
 * The customer reads answer only the starts on the interval, 15 by default. A suite whose
 * subject is the engine's five-minute grid sets 5, so those reads answer every start the grid
 * finds free and its expectations stay what the engine produces; the suites whose subject is the
 * interval itself leave the default or set the value they prove.
 */
final class StartInterval
{
    public static function set(string $schema, int $minutes): void
    {
        DB::table($schema.'.settings')->where('id', 1)->update(['start_interval_min' => $minutes]);
    }
}
