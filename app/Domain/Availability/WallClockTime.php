<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A reading of the wall clock with no date and no zone, as a shift pattern's or override's
 * windows and the time columns of blackouts carry it: hours, minutes and seconds from 00:00:00 to
 * 24:00:00 inclusive. The end of the day is a reading of its own so that a window can close at
 * midnight without naming a second past 23:59:59 and losing the last slot to rounding.
 *
 * A reading becomes an instant only against a staff-day, and only through PHP's own resolution
 * of that date and time in the tenant's zone. On a day the clocks change the resolution is
 * whatever the parser answers, pinned by test: a reading the clocks skip lands the width of the
 * gap later, and a reading the clocks repeat lands on the second pass. The end of the day is
 * the geometry's next midnight, never a parse of 24:00, which PHP accepts only with a warning.
 */
final readonly class WallClockTime
{
    private const string SPELLING = '/^(\d{2}):(\d{2})(?::(\d{2}))?$/';

    private const int END_OF_DAY = 86400;

    private function __construct(public int $secondsIntoDay) {}

    /**
     * @param  string  $text  HH:MM or HH:MM:SS, from 00:00 to 24:00
     */
    public static function of(string $text): self
    {
        if (preg_match(self::SPELLING, $text, $parts) === 1) {
            $seconds = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) ($parts[3] ?? '0');

            if ((int) $parts[2] < 60 && (int) ($parts[3] ?? '0') < 60 && $seconds <= self::END_OF_DAY) {
                return new self($seconds);
            }
        }

        throw new InvalidArgumentException(sprintf('A wall-clock time is spelled HH:MM or HH:MM:SS, from 00:00 to 24:00, not "%s".', $text));
    }

    public function isBefore(self $other): bool
    {
        return $this->secondsIntoDay < $other->secondsIntoDay;
    }

    public function equals(self $other): bool
    {
        return $this->secondsIntoDay === $other->secondsIntoDay;
    }

    public function format(): string
    {
        return sprintf(
            '%02d:%02d:%02d',
            intdiv($this->secondsIntoDay, 3600),
            intdiv($this->secondsIntoDay % 3600, 60),
            $this->secondsIntoDay % 60,
        );
    }

    /**
     * The instant this reading falls on during the staff-day, in the tenant's zone.
     */
    public function instantOn(StaffDayGeometry $geometry): DateTimeImmutable
    {
        if ($this->secondsIntoDay === self::END_OF_DAY) {
            return $geometry->nextMidnight;
        }

        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $geometry->day.' '.$this->format(), $geometry->zone);

        if ($instant === false) {
            throw new InvalidArgumentException(sprintf('%s on %s did not resolve in %s.', $this->format(), $geometry->day, $geometry->zone->getName()));
        }

        return $instant;
    }

    /**
     * The instant this reading would fall on during the staff-day if the one offset held all day,
     * seconds east of UTC: the day's date at this reading, less the offset, on the timeline. No
     * parser and no resolution, so a reading the clocks skip or repeat is placed the same way as
     * any other; the caller clips the result to the stretch of the day the offset does hold, which
     * StaffDayGeometry::offsetPeriods() answers. On a plain day the one offset holds throughout
     * and this agrees with instantOn() for every reading, the end of the day included.
     */
    public function instantUnder(StaffDayGeometry $geometry, int $offset): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $geometry->day, new DateTimeZone('UTC'));

        if ($date === false) {
            throw new InvalidArgumentException(sprintf('A day is spelled YYYY-MM-DD, not "%s".', $geometry->day));
        }

        return $date->setTimestamp($date->getTimestamp() + $this->secondsIntoDay - $offset)->setTimezone($geometry->zone);
    }
}
