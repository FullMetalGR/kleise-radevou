<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;

/**
 * A half-open range of slot positions on one staff-day, cut from a range of instants: the one
 * place a range that may leave the day, or fall between slot starts, becomes a from and a to
 * the mask can paint. Everything that paints a mask from instants comes through here, so the
 * clipping and the rounding are decided once.
 *
 * A range is clipped to the day by its sides: a start before the day is the first position,
 * an end after it is the width, and a range wholly outside is empty. The next midnight is
 * inside the day at the width, so a range ending exactly there closes the last slot and
 * touches nothing on the next day, and one starting there is empty here.
 *
 * Rounding goes one of two ways and both err toward less availability. Outward takes the
 * floor of the start and the ceiling of the end, for a block or a blackout: whatever it
 * touches, it removes. Inward takes the ceiling of the start and the floor of the end, for a
 * rule: a member on duty from 09:03 is not offered a slot starting 09:00.
 *
 * An end that lands before its start, which the parser's resolution of a wall-clock window
 * across the spring gap can produce, is an empty span and never an inverted one.
 */
final readonly class SlotSpan
{
    private function __construct(
        public int $from,
        public int $to,
    ) {}

    public static function outward(StaffDayGeometry $geometry, DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return self::clipped($geometry, $from, $to, $geometry->indexAtOrBefore(...), $geometry->indexAtOrAfter(...));
    }

    public static function inward(StaffDayGeometry $geometry, DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return self::clipped($geometry, $from, $to, $geometry->indexAtOrAfter(...), $geometry->indexAtOrBefore(...));
    }

    public function isEmpty(): bool
    {
        return $this->from === $this->to;
    }

    /**
     * @param  callable(DateTimeImmutable): int  $roundStart
     * @param  callable(DateTimeImmutable): int  $roundEnd
     */
    private static function clipped(StaffDayGeometry $geometry, DateTimeImmutable $from, DateTimeImmutable $to, callable $roundStart, callable $roundEnd): self
    {
        $start = match ($geometry->side($from)) {
            DaySide::Before => 0,
            DaySide::Within => $roundStart($from),
            DaySide::After => $geometry->width,
        };

        $end = match ($geometry->side($to)) {
            DaySide::Before => 0,
            DaySide::Within => $roundEnd($to),
            DaySide::After => $geometry->width,
        };

        return new self($start, max($start, $end));
    }
}
