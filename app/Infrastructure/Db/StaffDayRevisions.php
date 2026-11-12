<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Booking\LockKey;
use App\Domain\Booking\Revisions;
use DateTimeImmutable;

/**
 * What one revisions read answers: the revision of every mutex row that exists among the
 * staff-days asked for, and the server's now from the same statement. The now travels with the
 * revisions because it is the clock the blocking predicate reads, and an entry whose earliest
 * hold expiry is judged against any other clock would be served busy after the predicate
 * stopped counting the hold, or dropped before.
 */
final readonly class StaffDayRevisions
{
    public function __construct(
        public Revisions $revisions,
        public DateTimeImmutable $now,
    ) {}

    /**
     * The revision of a staff-day's mutex row, or null when no row exists yet. The lock
     * creates a missing row at zero rather than one, so absent and zero are two values and a
     * mask cached before a staff-day's first booking is never served after it.
     */
    public function of(int $staffId, string $day): ?int
    {
        $key = new LockKey($staffId, $day);

        return $this->revisions->has($key) ? $this->revisions->of($key) : null;
    }
}
