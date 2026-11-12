<?php

declare(strict_types=1);

namespace App\Domain\Availability;

/**
 * Where an instant lies against one staff-day: before its midnight, within it, or after its
 * next midnight. Both midnights count as within, because the next midnight is the position
 * that closes the day's last slot and a range ending exactly there needs no clipping. A
 * caller clipping a range to the day reads this rather than catching the refusal a conversion
 * raises for an outside instant.
 */
enum DaySide
{
    case Before;
    case Within;
    case After;
}
