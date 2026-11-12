<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use App\Domain\Booking\Overridable;
use InvalidArgumentException;
use LogicException;

/**
 * The two masks of one staff-day, kept apart because they constrain different spans of a
 * segment: the open mask, availability minus blackouts, and the not-booked mask, every slot
 * minus the block range of every item that blocks. Both are the width of the one day they
 * describe, so a pair of different widths is refused.
 *
 * Beside the open mask, where the read that built it kept them apart, its two factors: the
 * member's hours, their rules expanded with no blackout taken off, and what no closure covers,
 * every slot minus the blackouts that reach the member on that day. The open mask is exactly
 * their intersection. They are carried for one question, which of the two a range crosses, the
 * warning a business move names; a read that did not keep them apart answers every crossing as
 * the member's hours, which is the open mask's own reading.
 */
final readonly class StaffDayMasks
{
    public FreeBusyMask $hours;

    public FreeBusyMask $unclosed;

    public function __construct(
        public FreeBusyMask $open,
        public FreeBusyMask $notBooked,
        ?FreeBusyMask $hours = null,
        ?FreeBusyMask $unclosed = null,
    ) {
        if ($open->width !== $notBooked->width) {
            throw new InvalidArgumentException(sprintf('The two masks of a staff-day share its width, not %d and %d.', $open->width, $notBooked->width));
        }

        $this->hours = $hours ?? $open;
        $this->unclosed = $unclosed ?? FreeBusyMask::allSet($open->width);

        if ($this->hours->width !== $open->width || $this->unclosed->width !== $open->width) {
            throw new InvalidArgumentException(sprintf('The factors of an open mask share its width, not %d and %d beside %d.', $this->hours->width, $this->unclosed->width, $open->width));
        }
    }

    /**
     * Whether one explicit item fits this staff-day, judged over the ranges an item stores
     * rather than a shape: every slot of its contended span is not booked, and every slot of
     * its service span is open. The two spans are checked against different masks for the
     * reason the masks are kept apart: buffers occupy the member and contend, but need not fall
     * inside opening hours. A span that does not touch this day is empty and asks nothing.
     */
    public function admits(SlotSpan $service, SlotSpan $contended): bool
    {
        return ! $this->booked($contended) && $this->crossed($service) === [];
    }

    /**
     * Whether some slot of the contended span is taken by an item that blocks: the half of the
     * question nobody may override.
     */
    public function booked(SlotSpan $contended): bool
    {
        return ! self::freeOver($this->notBooked, $contended);
    }

    /**
     * What of the open half the service span crosses, each kind once in the order hours then
     * closure, and nothing where every slot of it is open.
     *
     * @return list<Overridable>
     */
    public function crossed(SlotSpan $service): array
    {
        if (self::freeOver($this->open, $service)) {
            return [];
        }

        $kinds = [];

        if (! self::freeOver($this->hours, $service)) {
            $kinds[] = Overridable::Hours;
        }

        if (! self::freeOver($this->unclosed, $service)) {
            $kinds[] = Overridable::Closure;
        }

        if ($kinds === []) {
            throw new LogicException('A span closed on the open mask is open on both of its factors, so the factors are not the open mask\'s.');
        }

        return $kinds;
    }

    private static function freeOver(FreeBusyMask $mask, SlotSpan $span): bool
    {
        return $span->isEmpty() || $mask->runs($span->to - $span->from)->isSet($span->from);
    }
}
