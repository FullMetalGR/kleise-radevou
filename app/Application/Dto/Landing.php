<?php

declare(strict_types=1);

namespace App\Application\Dto;

use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use InvalidArgumentException;

/**
 * Where the write protocol leaves a chain once it has proven it: on a hold, in the held status
 * the planner derived from the catalog with the class and lifetime that go with it, or confirmed
 * outright, with no class and no expiry. The second is the manual booking: an operator booking
 * for a customer who called is not a customer still inside the booking flow, so there is nothing
 * to hold and nothing to lapse.
 *
 * The plan is the same either way, derived once from the catalog; the landing is the one thing
 * the two writes decide differently, so it is the one thing they are told apart by.
 */
final readonly class Landing
{
    private function __construct(
        public BookingStatus $status,
        public ?HoldClass $holdClass,
        public ?int $ttlMinutes,
    ) {
        if ($status->isHeld() !== ($holdClass !== null && $ttlMinutes !== null)) {
            throw new InvalidArgumentException(sprintf('A landing on %s %s a hold class and a lifetime.', $status->value, $status->isHeld() ? 'needs' : 'carries no'));
        }
    }

    /** The hold as the planner derived it: held, classed and timed from the catalog. */
    public static function held(HoldPlan $plan): self
    {
        return new self($plan->status, $plan->holdClass, $plan->ttlMinutes);
    }

    /** Confirmed directly, never a hold. */
    public static function confirmed(): self
    {
        return new self(BookingStatus::Confirmed, null, null);
    }

    public function isHeld(): bool
    {
        return $this->holdClass !== null;
    }
}
