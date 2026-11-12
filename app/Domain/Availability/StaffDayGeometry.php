<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The geometry of one staff-day in the tenant's zone: the instant its local midnight falls on,
 * the instant the next local midnight falls on, and how many slots of the granularity lie
 * between them. The width is a property of the day rather than a constant: a day the clocks
 * change is 23 or 25 hours long and has 276 or 300 slots at five minutes.
 *
 * Slot index i is the instant midnight plus i steps. Masks index instants, never wall-clock
 * readings, which is what keeps the repeated autumn hour two distinct sets of slots and the
 * missing spring hour no slots at all. The positions run from 0 to the width inclusive, the
 * width being the position that closes the last slot; the slots themselves are the half-open
 * ranges between neighbouring positions.
 *
 * This is the one place instants and indices meet. Every later step converts through it and
 * none of them does its own zone arithmetic. The staff member is not part of the geometry: the
 * same day is the same shape for everyone in the tenant.
 */
final readonly class StaffDayGeometry
{
    private const string DAY = '/^(\d{4})-(\d{2})-(\d{2})$/';

    public DateTimeImmutable $midnight;

    public DateTimeImmutable $nextMidnight;

    public int $width;

    private int $step;

    private function __construct(
        public string $day,
        public DateTimeZone $zone,
        public Granularity $granularity,
    ) {
        $this->midnight = self::localMidnight($day, $zone);
        $this->nextMidnight = self::localMidnight(self::following($day), $zone);
        $this->step = $granularity->minutes * 60;

        $seconds = $this->nextMidnight->getTimestamp() - $this->midnight->getTimestamp();

        if ($seconds % $this->step !== 0) {
            throw new InvalidArgumentException(sprintf(
                'The day %s in %s is %d minutes long, not a whole number of %d-minute slots.',
                $day,
                $zone->getName(),
                intdiv($seconds, 60),
                $granularity->minutes,
            ));
        }

        $this->width = intdiv($seconds, $this->step);
    }

    /**
     * @param  string  $day  a calendar date in the zone, spelled YYYY-MM-DD as the day column and LockKey spell it
     */
    public static function of(string $day, DateTimeZone $zone, Granularity $granularity): self
    {
        if (preg_match(self::DAY, $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException(sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
        }

        return new self($day, $zone, $granularity);
    }

    /**
     * Before the day's midnight, after its next midnight, or within, both midnights included.
     */
    public function side(DateTimeImmutable $instant): DaySide
    {
        if (self::compare($instant, $this->midnight) < 0) {
            return DaySide::Before;
        }

        if (self::compare($instant, $this->nextMidnight) > 0) {
            return DaySide::After;
        }

        return DaySide::Within;
    }

    /**
     * The index of the slot that starts at or before the instant: the floor.
     *
     * @throws InstantOutsideDay
     */
    public function indexAtOrBefore(DateTimeImmutable $instant): int
    {
        return intdiv($this->secondsInto($instant), $this->step);
    }

    /**
     * The index of the slot that starts at or after the instant: the ceiling, the width when
     * the instant is the next midnight.
     *
     * @throws InstantOutsideDay
     */
    public function indexAtOrAfter(DateTimeImmutable $instant): int
    {
        $seconds = $this->secondsInto($instant);
        $index = intdiv($seconds, $this->step);

        if ($seconds % $this->step !== 0 || (int) $instant->format('u') !== 0) {
            $index++;
        }

        return $index;
    }

    /**
     * The instant a position falls on, in the tenant's zone, the width giving the next
     * midnight.
     */
    public function instantAt(int $index): DateTimeImmutable
    {
        if ($index < 0 || $index > $this->width) {
            throw new InvalidArgumentException(sprintf('An index on %s lies between 0 and %d, not %d.', $this->day, $this->width, $index));
        }

        return $this->midnight->setTimestamp($this->midnight->getTimestamp() + $index * $this->step);
    }

    /**
     * The stretches of the day on which one UTC offset holds, in order and meeting end to start:
     * one from midnight to the next midnight on a plain day, two on a day the clocks change
     * inside it, meeting at the change. A change that falls exactly on either midnight splits
     * nothing, since the whole day then runs on the offset the clocks landed on; the day is
     * still 23 or 25 hours wide on the side the change happened.
     *
     * The zone answers where it changes between the two midnights. The change is listed a
     * second time when it falls exactly on the first bound and listed at all when it falls on
     * the last, so only changes strictly inside the day are taken. A zone with no transition
     * table, a fixed offset, answers false rather than an empty list, and reads as none.
     *
     * @return non-empty-list<OffsetPeriod>
     */
    public function offsetPeriods(): array
    {
        $begin = $this->midnight->getTimestamp();
        $end = $this->nextMidnight->getTimestamp();

        $periods = [];
        $from = $this->midnight;
        $offset = $this->midnight->getOffset();

        foreach ($this->zone->getTransitions($begin, $end) ?: [] as $transition) {
            if ($transition['ts'] <= $begin || $transition['ts'] >= $end) {
                continue;
            }

            $at = $this->midnight->setTimestamp($transition['ts']);
            $periods[] = new OffsetPeriod($offset, $from, $at);
            $from = $at;
            $offset = $transition['offset'];
        }

        $periods[] = new OffsetPeriod($offset, $from, $this->nextMidnight);

        return $periods;
    }

    /**
     * Whole seconds from midnight to the instant, the microseconds dropped, for an instant the
     * day contains.
     */
    private function secondsInto(DateTimeImmutable $instant): int
    {
        $side = $this->side($instant);

        if ($side !== DaySide::Within) {
            throw InstantOutsideDay::on($this, $instant, $side);
        }

        return $instant->getTimestamp() - $this->midnight->getTimestamp();
    }

    /**
     * Two instants by their position on the timeline, to the microsecond, whatever zone
     * either is spelled in.
     */
    private static function compare(DateTimeImmutable $left, DateTimeImmutable $right): int
    {
        return [$left->getTimestamp(), (int) $left->format('u')] <=> [$right->getTimestamp(), (int) $right->format('u')];
    }

    /**
     * The first instant of the calendar day in the zone. On a day whose midnight the clocks
     * skip, that is the instant the clocks land on, which the parser already answers.
     */
    private static function localMidnight(string $day, DateTimeZone $zone): DateTimeImmutable
    {
        $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);

        if ($midnight === false) {
            throw new InvalidArgumentException(sprintf('A day is spelled YYYY-MM-DD, not "%s".', $day));
        }

        return $midnight;
    }

    /**
     * The calendar day after this one. Counted in UTC so that no zone's skipped or repeated
     * hour can move the date, and rebuilt in the zone by the caller.
     */
    private static function following(string $day): string
    {
        return (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }
}
