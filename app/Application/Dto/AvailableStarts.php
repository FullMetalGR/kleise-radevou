<?php

declare(strict_types=1);

namespace App\Application\Dto;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * What QueryAvailability answers: for every day asked for, in order, the instants at which
 * the whole chain can start, ascending, an empty list for a day with none. The keys are the
 * tenant-local dates as the query spelled them, and the instants carry the tenant's zone;
 * the HTTP layer spells them for the wire. A client builds its date strip from the keys and
 * its grid from one key's list, and never computes a date itself.
 *
 * every() shapes the customer's offer: the two customer reads thin the answer to the tenant's
 * start_interval_min, a quarter hour unless the tenant chose otherwise, so the booking app offers
 * a start on that interval rather than at every free step. It is not the engine's grid, which
 * stays granularity_min and which every start here already lies on, and the admin's read answers
 * every start so the operator can place one by hand. Nor is it a check on writes: a hold, a
 * confirm or a move at a free start off the interval is accepted, so a start offered is still a
 * start accepted and an API caller with a front end of its own loses nothing.
 */
final readonly class AvailableStarts
{
    /**
     * @param  array<string, list<DateTimeImmutable>>  $byDay  by day, in the order asked for
     */
    public function __construct(public array $byDay) {}

    /**
     * Nothing on any of the days: the answer for a chain nobody can perform.
     *
     * @param  list<string>  $days
     */
    public static function none(array $days): self
    {
        return new self(array_fill_keys($days, []));
    }

    /**
     * The first day asked for that holds a start, in the order asked for, or null when every
     * day is empty: what the public read asks of its answer before it reads further, and what
     * the forward walk asks of each window it reads (next_available_from).
     */
    public function firstDayWithAStart(): ?string
    {
        foreach ($this->byDay as $day => $starts) {
            if ($starts !== []) {
                return (string) $day;
            }
        }

        return null;
    }

    /**
     * The same answer with one instant left out of whichever day holds it, compared as an
     * instant and not as a spelling: the per-booking read drops the booking's current start.
     */
    public function without(DateTimeImmutable $instant): self
    {
        $byDay = [];

        foreach ($this->byDay as $day => $starts) {
            $byDay[$day] = array_values(array_filter($starts, fn (DateTimeImmutable $start): bool => $start->getTimestamp() !== $instant->getTimestamp()));
        }

        return new self($byDay);
    }

    /**
     * The same answer keeping only the starts whose minute of the hour, read on the wall clock
     * in the tenant's zone, is a multiple of the interval: minute zero alone at 60, and 09:15
     * first for a window opening at 09:10 at 15. The wall clock and not the slot index, because
     * on a day the clocks change the two part company, and every interval divides the hour, so
     * the rule is the same in every hour of every day. Every day keeps its key, an emptied one
     * as an empty list.
     */
    public function every(int $minutes, DateTimeZone $zone): self
    {
        if ($minutes < 1 || 60 % $minutes !== 0) {
            throw new InvalidArgumentException(sprintf('A start interval is a number of minutes dividing sixty, not %d.', $minutes));
        }

        $byDay = [];

        foreach ($this->byDay as $day => $starts) {
            $byDay[$day] = array_values(array_filter($starts, fn (DateTimeImmutable $start): bool => (int) $start->setTimezone($zone)->format('i') % $minutes === 0));
        }

        return new self($byDay);
    }
}
