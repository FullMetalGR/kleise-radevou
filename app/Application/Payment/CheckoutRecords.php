<?php

declare(strict_types=1);

namespace App\Application\Payment;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The booking row as the pay hop reads and writes it, one tenant's. The read is one statement
 * outside any transaction. The write opens its own transaction and takes the booking's
 * staff-day locks before the booking row, so neither is ever called inside a transaction or
 * under a lock: the provider is asked between the two.
 */
interface CheckoutRecords
{
    public function state(string $uuid): ?CheckoutState;

    /**
     * Records the session opened for a booking and moves its hold's expiry to the session's end,
     * in one statement guarded by the row as it stands when the statement takes it: only a hold
     * still live, of a held status a payment settles, with no session recorded yet. Answers
     * whether it wrote; a hold that ended meanwhile is not revived. A write that lost a race
     * answers false and wrote nothing: a guard the row no longer meets, the booking's items moved
     * off the locked staff-days on every attempt, or a busy answer, a staff-day held past the
     * lock timeout (Busy) or retries exhausted; the caller reads the row again. Every other
     * failure of the write is a fault, not a race, and reaches the handler.
     *
     * The same statement seals the manage token the hop's request presented onto the row, the one
     * form the confirmation after the payment can carry the customer's link from; the transition
     * that ends the hold clears it. The signed link of the approved message presents no token, so
     * on that road the column is written null: there is nothing to seal.
     */
    public function record(string $uuid, string $paymentRef, DateTimeImmutable $endsAt, #[SensitiveParameter] ?string $manageToken): bool;
}
