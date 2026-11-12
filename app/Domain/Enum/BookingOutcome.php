<?php

declare(strict_types=1);

namespace App\Domain\Enum;

use App\Domain\Audit\AuditAction;

/**
 * How an appointment ended: it happened, or the customer did not arrive. The two outcomes of a
 * confirmed booking, and the two buttons the admin offers for them.
 *
 * One type rather than two use cases, because everything that decides whether the owner may say
 * this is the same for both, and the only difference between them is which status they land on,
 * what the trail calls it and which record goes out. A guard that must be identical for two
 * transitions is kept identical most reliably by there being one of it.
 *
 * Both are spelled out rather than read off this enum's own value, so that a status or an
 * action renamed on its own side does not silently restate what an outcome means.
 */
enum BookingOutcome: string
{
    case Completed = 'completed';

    case NoShow = 'no_show';

    public function status(): BookingStatus
    {
        return match ($this) {
            self::Completed => BookingStatus::Completed,
            self::NoShow => BookingStatus::NoShow,
        };
    }

    public function audit(): AuditAction
    {
        return match ($this) {
            self::Completed => AuditAction::Completed,
            self::NoShow => AuditAction::NoShow,
        };
    }
}
