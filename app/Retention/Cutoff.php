<?php

declare(strict_types=1);

namespace App\Retention;

use App\Application\Clock;
use App\Infrastructure\Db\SqlText;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Where a period kept up to the clock's now began, spelled as a statement binds it: UTC text with
 * its offset. The period is counted back from the instant in UTC, whatever zone the clock spells
 * it in, so a period of days is that many times 24 hours of elapsed time and a period of months
 * that many calendar months of UTC; counted on a wall clock that changes its offset, as Athens'
 * does twice a year, the start would sit an hour away from the true one.
 *
 * A row is past its period when its instant is earlier than this, so a row at the instant itself
 * is still inside: kept twelve months means kept to the end of the twelfth.
 */
final readonly class Cutoff
{
    /**
     * @param  string  $kept  an ISO 8601 duration, the line's own constant
     */
    public static function before(Clock $clock, string $kept): string
    {
        return SqlText::instant(self::at($clock, $kept));
    }

    /**
     * The same start as an instant in UTC, for a reader that decides on a row it already holds,
     * as the close of a lapsed subscriber does under its lock.
     *
     * @param  string  $kept  an ISO 8601 duration, the line's own constant
     */
    public static function at(Clock $clock, string $kept): DateTimeImmutable
    {
        return $clock->now()->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval($kept));
    }
}
