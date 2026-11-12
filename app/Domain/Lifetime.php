<?php

declare(strict_types=1);

namespace App\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A length of elapsed time the code keeps as an ISO 8601 interval, read as the whole hours or the
 * whole days the mails and the screens count it by: the set-password link's lifetime, the
 * verification link's, the trial's and the closing window's. The interval is added to the epoch in
 * UTC and read back in seconds, so minutes, days or months count exactly rather than by one field
 * of the interval, any part of an hour or a day left over dropped. Pure PHP, so every layer reads
 * it.
 */
final readonly class Lifetime
{
    /**
     * How many whole hours the interval spans.
     */
    public static function hours(string $interval): int
    {
        return intdiv(self::seconds($interval), 3600);
    }

    /**
     * How many whole days the interval spans, the whole hours it makes counted by twenty-four.
     */
    public static function days(string $interval): int
    {
        return intdiv(self::hours($interval), 24);
    }

    private static function seconds(string $interval): int
    {
        $epoch = new DateTimeImmutable('@0', new DateTimeZone('UTC'));

        return $epoch->add(new DateInterval($interval))->getTimestamp();
    }
}
