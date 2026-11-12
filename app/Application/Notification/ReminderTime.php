<?php

declare(strict_types=1);

namespace App\Application\Notification;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When a reminder for an appointment goes: the tenant's lead before the first start, or not at
 * all where that instant has already passed.
 *
 * **The lead is elapsed time between two instants and never a wall-clock unit**. A clock
 * change lies between now and the start for part of every year, and a day before on the
 * tenant's wall clock is 23 or 25 hours of elapsed time on those days. The queue counts
 * elapsed seconds, so the delay it is handed has to be the same kind of number: the start is
 * taken as a position on the timeline, the lead is subtracted as hours of it, and the answer
 * is spelled in UTC, where there is no clock change to be confused by. The message that
 * eventually goes renders the appointment in the tenant's clock; this decides only when.
 *
 * **A booking already inside its lead gets no reminder.** A booking confirmed twenty hours
 * before its start with a lead of twenty-four would otherwise be reminded the moment it was
 * confirmed, and a reminder sent a minute after the confirmation is not a reminder. The
 * boundary is closed: at exactly the lead the reminder would be sent now, which is the same
 * case.
 *
 * **A lead of zero is refused here rather than answered.** A lead of zero switches reminders
 * off, and that is the scheduler's decision to make before it asks when: a caller asking this
 * for the instant of a reminder the tenant does not send has misread the setting, and the
 * wrong answer would be a reminder at the start itself.
 */
final readonly class ReminderTime
{
    /**
     * @param  DateTimeImmutable  $start  the first start of the booking, in any zone
     * @param  int  $leadHours  the tenant's reminder lead, above zero
     * @param  DateTimeImmutable  $now  the current instant, from the Clock port
     * @return DateTimeImmutable|null the instant the reminder goes, in UTC, or null where it would already have gone
     */
    public static function of(DateTimeImmutable $start, int $leadHours, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($leadHours < 1) {
            throw new InvalidArgumentException(sprintf('A reminder lead here is at least one hour, not %d; zero is no reminder and is decided before asking when.', $leadHours));
        }

        // Subtracted in UTC, where an hour is always sixty minutes of elapsed time, and as a
        // DateInterval on the instant rather than through setTimestamp(), which drops the
        // microseconds on this PHP. Nothing that reads the answer needs them, the queue stores the
        // instant in whole seconds; they are kept because the answer is the start less the lead and
        // not a rounding of it.
        $sendAt = $start->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval(sprintf('PT%dH', $leadHours)));

        return $sendAt > $now ? $sendAt : null;
    }
}
