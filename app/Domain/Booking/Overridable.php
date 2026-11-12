<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * What of the open half of a staff-day a segment's service range crosses: the member's working
 * hours, which are their weekly pattern and the days changed alone, or a closure, which is a
 * blackout, the member's own or the business's, a whole closed day included.
 *
 * These are the two the business may move a booking onto after a warning it accepted; nothing
 * else is. Another booking in the way is never one of these: the not-booked half is not
 * overridable by anybody, and the exclusion constraint stands behind it.
 */
enum Overridable: string
{
    case Hours = 'hours';

    case Closure = 'closure';
}
