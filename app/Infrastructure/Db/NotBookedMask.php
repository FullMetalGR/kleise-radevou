<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Availability\FreeBusyMask;
use DateTimeImmutable;

/**
 * The not-booked mask of one staff-day as the rows gave it, and the earliest expiry among the
 * held items it counted as busy, null when it counted none. This is what the mask cache stores
 * and hands back: the revision in the key says when a write changed the rows, and the expiry
 * says when the clock alone may. A hold that lapses bumps nothing, so an entry may not outlive
 * the earliest expiry it depended on. An approval request that auto-approves on timeout stays
 * busy past its window, but the sweeper grants it there into a payment hold whose expiry no
 * revision announces, so its window bounds an entry too. A confirmed item is freed only by a
 * write, and sets no expiry.
 *
 * Kept out of Domain on purpose. The builder takes ranges and nothing about the rows they came
 * from, so that the blocking predicate keeps its one implementation, the items statement; the
 * expiry beside the mask is what that statement said, carried and never re-decided.
 */
final readonly class NotBookedMask
{
    public function __construct(
        public FreeBusyMask $mask,
        public ?DateTimeImmutable $earliestHoldExpiry = null,
    ) {}

    /**
     * Whether the mask may no longer be served at the instant: its earliest hold expiry is at
     * or before it, so the predicate may count one item fewer than the mask does, or a request
     * the clock granted may since have become a payment hold that ends.
     */
    public function lapsedBy(DateTimeImmutable $now): bool
    {
        return $this->earliestHoldExpiry !== null && $this->earliestHoldExpiry <= $now;
    }
}
