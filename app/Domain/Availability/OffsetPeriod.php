<?php

declare(strict_types=1);

namespace App\Domain\Availability;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A stretch of one staff-day during which one UTC offset holds: from an instant up to but not
 * including the next, and the offset in seconds east of UTC that every reading inside it is
 * made under. A plain day is one of these; a day the clocks change inside it is two, meeting at
 * the change. The last period of a day ends at the next midnight, which the geometry counts as
 * within the day, so a range clipped to it can still close the last slot.
 *
 * What it is for: a wall-clock window read under an offset is only meaningful during the stretch
 * that offset holds, so a caller reads the window under each period's offset and clips the result
 * to that period. Both passes of a repeated hour survive, because each lies in its own period,
 * and a reading the clocks skip clips to nothing, because no period holds it.
 */
final readonly class OffsetPeriod
{
    public function __construct(
        public int $offset,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($to <= $from) {
            throw new InvalidArgumentException(sprintf(
                'An offset period ends after it starts, not from %s to %s.',
                $from->format(DateTimeImmutable::RFC3339),
                $to->format(DateTimeImmutable::RFC3339),
            ));
        }
    }

    /**
     * The part of a range of instants that lies inside this period: the later of the two starts
     * and the earlier of the two ends. A range wholly outside comes back inverted, its end before
     * its start, which the span that is cut from it reads as empty.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function clip(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            $from > $this->from ? $from : $this->from,
            $to < $this->to ? $to : $this->to,
        ];
    }
}
