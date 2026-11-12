<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The spelling of a calendar day that carries no instant, YYYY-MM-DD, as the date columns, the
 * staff-day key and StaffDayGeometry all spell it. A day is a string at every boundary rather
 * than an object, so this holds the checks a string needs: that it is a real date, which
 * weekday it is, and how two of them order. Counted in UTC throughout, since a calendar day is
 * the same date in every zone and only its instants differ.
 */
final class CalendarDay
{
    private const string SPELLING = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * The day itself, refused unless it is spelled YYYY-MM-DD and is a date.
     */
    public static function spelled(string $day): string
    {
        if (preg_match(self::SPELLING, $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException(sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
        }

        return $day;
    }

    /**
     * The ISO weekday, Monday one to Sunday seven.
     */
    public static function weekday(string $day): int
    {
        return (int) (new DateTimeImmutable(self::spelled($day), new DateTimeZone('UTC')))->format('N');
    }

    /**
     * Minus one, zero or one, as the calendar orders the two days. Both are spelled with fixed
     * widths, so the spelling orders as the dates do.
     */
    public static function compare(string $left, string $right): int
    {
        return strcmp(self::spelled($left), self::spelled($right)) <=> 0;
    }

    /**
     * The number of days from one day to another: positive when the second comes later,
     * negative when it comes first, zero for the same day. Counted in UTC, where every day is
     * 24 hours, so a clock change between them moves nothing.
     */
    public static function daysBetween(string $from, string $to): int
    {
        $utc = new DateTimeZone('UTC');
        $seconds = (new DateTimeImmutable(self::spelled($to), $utc))->getTimestamp() - (new DateTimeImmutable(self::spelled($from), $utc))->getTimestamp();

        return intdiv($seconds, 86_400);
    }

    /**
     * The first day of the week holding a day, the week starting on the ISO weekday given: the
     * rule the calendar opens a week by (CalendarWindow::weekOf) and a shift pattern counts its
     * cadence by.
     *
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     */
    public static function weekStart(string $day, int $startOfWeek): string
    {
        if ($startOfWeek < 1 || $startOfWeek > 7) {
            throw new InvalidArgumentException(sprintf('A week starts on an ISO weekday, 1 to 7, not %d.', $startOfWeek));
        }

        return self::plusDays($day, -((self::weekday($day) - $startOfWeek + 7) % 7));
    }

    /**
     * The number of weeks from the week holding one day to the week holding another, the weeks
     * starting on the ISO weekday given: zero inside one week, negative when the second day's
     * week comes first. Two days of one week are zero apart whatever days of it they are.
     *
     * @param  int  $startOfWeek  ISO numbered, 1 for Monday
     */
    public static function weeksBetween(string $from, string $to, int $startOfWeek): int
    {
        return intdiv(self::daysBetween(self::weekStart($from, $startOfWeek), self::weekStart($to, $startOfWeek)), 7);
    }

    /**
     * The day a number of days later, or earlier for a negative count. Counted in UTC like the
     * rest of this class, so no zone's skipped or repeated hour can move the date.
     */
    public static function plusDays(string $day, int $days): string
    {
        return (new DateTimeImmutable(self::spelled($day), new DateTimeZone('UTC')))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
