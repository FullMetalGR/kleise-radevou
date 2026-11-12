<?php

declare(strict_types=1);

namespace App\Application\Notification;

use App\Application\Dto\HoldWindow;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When the business is nagged about a request nobody has decided: a quarter, a half and three
 * quarters of the way from the hold's start to its expiry.
 *
 * **The fractions are of elapsed time between two instants and never of a wall-clock unit**. A
 * clock change can lie inside a forty-eight hour window, and a quarter of the window by the
 * tenant's wall clock would be a quarter of forty-seven or forty-nine hours on those days. The
 * queue counts elapsed seconds, so the instants it is handed are the same kind of number: the
 * window's length is taken as seconds on the timeline, each fraction of it is added to the start
 * as seconds, and the answers are spelled in UTC, where there is no clock change to be confused
 * by. The message that eventually goes renders its instants in the tenant's clock; this decides
 * only when.
 *
 * **No clock of this application is in the answer.** The window is the database's, HoldWindow,
 * so the three instants are the database's too: in production the queue's clock and the
 * database's agree, and in a test that pins the Clock port the nags still fall where the row
 * says they do, which is the only place a test can check them against.
 *
 * Each of the three fractions floors to a whole second. No window the insert writes needs it,
 * because the insert writes the window in whole minutes, PostgresBookingWriter, and a minute
 * divides by four into whole seconds; the flooring is stated so that a hand-written row cannot
 * make this throw.
 */
final readonly class NagTimes
{
    /**
     * @return array{DateTimeImmutable, DateTimeImmutable, DateTimeImmutable} the three instants in the order they fall, in UTC
     */
    public static function of(HoldWindow $window): array
    {
        $seconds = $window->until->getTimestamp() - $window->from->getTimestamp();
        $from = $window->from->setTimezone(new DateTimeZone('UTC'));

        // Quarters, and three of them: 25, 50 and 75 percent of the window are fixed, so the
        // fractions are spelled rather than parameterised, since a number here that could
        // change would be a number that must not.
        return [
            self::after($from, intdiv($seconds, 4)),
            self::after($from, intdiv($seconds * 2, 4)),
            self::after($from, intdiv($seconds * 3, 4)),
        ];
    }

    /**
     * Added as a DateInterval on the instant rather than through setTimestamp(), which drops
     * the microseconds on this PHP. Nothing that reads the answer needs them, the queue stores
     * the instant in whole seconds; they are kept because the answer is the start plus a
     * number of seconds and not a rounding of it.
     */
    private static function after(DateTimeImmutable $from, int $seconds): DateTimeImmutable
    {
        return $from->add(new DateInterval(sprintf('PT%dS', $seconds)));
    }
}
