<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The span an approval request waits in: from the instant the hold was written to the instant
 * it runs out, both as the database wrote them.
 *
 * It is one value rather than two instants because the two are one fact: the hold insert sets
 * hold_expires_at from now() and the window's length in one statement, and created_at defaults
 * to the same now(), so the start and the end come from one clock and one transaction and the
 * length between them is the TTL exactly. A caller measuring a fraction of the way through the
 * window measures between these two and never against a clock of its own, which is what keeps
 * the nags on the database's clock, the one the expiry was written by and the one the sweep
 * judges it by.
 *
 * A window that ends before it starts, or at the same instant, is refused: nothing can be a
 * quarter of the way through it, and a row that carried one would be a row the insert never
 * wrote. The refusal is unreachable through the insert, whose window is the plan's TTL after
 * created_at exactly, PostgresBookingWriter, and Settings and HoldPlan both refuse a TTL under
 * one minute; it stands for a hand-written row, and names both instants so that row can be
 * found.
 */
final readonly class HoldWindow
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
    ) {
        if ($until <= $from) {
            throw new InvalidArgumentException(sprintf(
                'A hold window ends after it starts; this one runs from %s to %s.',
                $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
                $until->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            ));
        }
    }
}
