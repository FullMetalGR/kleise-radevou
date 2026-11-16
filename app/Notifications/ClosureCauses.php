<?php

declare(strict_types=1);

namespace App\Notifications;

use Closure;

/**
 * The bookings being cancelled or rejected, at this moment of this request, because the business
 * added a closure over them, each with the closure's cause.
 *
 * A transition's record says who acted and what the booking was, never why: the cancel and the
 * reject are the same use cases whether the business pressed Cancel on one booking or cancelled
 * every booking inside a new closure. The customer is told differently in the second case, with
 * the closure's own message in place of the usual one, so the bulk cancel names the cause here
 * around each transition, and the customer's two listeners, which run inside that transition's
 * call once it has committed, read it back by the booking.
 *
 * One instance per request or job, so nothing named here outlives the call that named it, and
 * the cause is forgotten when the act returns or throws.
 */
final class ClosureCauses
{
    /** @var array<string, ClosureCause> */
    private array $causes = [];

    /**
     * Runs the act with the booking's cause named, and forgets it afterwards whatever the act did.
     *
     * @template T
     *
     * @param  Closure(): T  $act
     * @return T
     */
    public function during(string $uuid, ClosureCause $cause, Closure $act): mixed
    {
        $this->causes[$uuid] = $cause;

        try {
            return $act();
        } finally {
            unset($this->causes[$uuid]);
        }
    }

    /** The cause named for the booking, null where none is. */
    public function of(string $uuid): ?ClosureCause
    {
        return $this->causes[$uuid] ?? null;
    }
}
