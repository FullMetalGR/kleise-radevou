<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Enum\BookingStatus;

/**
 * What the cancellation policy answered: allowed, or refused for one reason from the closed
 * set. A value rather than an exception because a refusal is the normal answer to a question,
 * not a failure of the code asking it. The status the policy evaluated rides along so a status
 * refusal names the status it refused on without the caller having to keep it beside the answer.
 *
 * The reason is the one vocabulary a refusal has, so a guard hands what it answered straight to
 * the transition it is guarding and nothing translates on the way.
 */
final readonly class PolicyDecision
{
    private function __construct(
        public BookingStatus $status,
        public ?TransitionRefusal $refusal,
    ) {}

    public static function allowed(BookingStatus $status): self
    {
        return new self($status, null);
    }

    public static function refused(BookingStatus $status, TransitionRefusal $refusal): self
    {
        return new self($status, $refusal);
    }

    public function isAllowed(): bool
    {
        return $this->refusal === null;
    }
}
