<?php

declare(strict_types=1);

namespace App\Application\Dto;

/**
 * Whom the Calendar shows, the Who control's four answers: everyone with hours in force on a
 * day of the window, every active member, the viewer's own staff row, or a set of members
 * picked by id. The wire's `who` spells the first three and a `staff` list stands for the
 * fourth; an unknown word reads as Scheduled, the default.
 */
enum CalendarWho: string
{
    case Scheduled = 'scheduled';
    case All = 'all';
    case Me = 'me';
    case Picked = 'picked';

    /** The word the query spells, or Scheduled for anything else: picked is never spelled, a list is. */
    public static function spelled(?string $word): self
    {
        $who = $word === null ? null : self::tryFrom($word);

        return $who === null || $who === self::Picked ? self::Scheduled : $who;
    }
}
