<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The first instant a bound can name, the first moment of the year 1 in UTC. An instant crosses
 * to the database as UTC text, and a year 0 is a year the database refuses to read; the midnight
 * of the calendar's own first day, 0001-01-01, falls in it in any zone east of UTC. No booking
 * precedes this instant, so a bound before it bounds nothing: the lists read such a From as
 * unbounded, and the calendar's window and the reports' range start here instead.
 */
final class FirstInstant
{
    public static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('0001-01-01 00:00', new DateTimeZone('UTC'));
    }

    public static function precedes(DateTimeImmutable $instant): bool
    {
        return $instant < self::at();
    }
}
