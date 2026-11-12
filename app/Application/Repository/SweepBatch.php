<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Application\Dto\LapsedHold;
use App\Application\Dto\ServerNowPlus;
use App\Domain\Audit\AuditEntry;
use DateTimeImmutable;
use LogicException;

/**
 * One batch of the sweeper, on the transaction a HoldSweep opened: the singleton guard, the
 * selection, the three settlements and the trail. The use case decides which of the three each
 * row takes and where the loop stops; the batch knows the SQL.
 *
 * Nothing here locks a staff-day and nothing here bumps a revision. The sweep writes down what
 * the clock already decided, so no availability read it could invalidate ever counted the holds
 * it lets go: a cached mask may not outlive the earliest expiry among the live holds it counted
 * as busy, and every hold this touches is past that instant.
 */
interface SweepBatch
{
    /**
     * The singleton guard, and the first statement of the transaction: the transaction-scoped
     * advisory lock in its two-integer form, a fixed class beside the tenant's central id, so
     * nothing is hashed and no two tenants share a key. False rather than a wait, and a false
     * answer is the second sweep standing down rather than an error.
     *
     * Never session-scoped. A session-level advisory lock leaks across pooled connections and
     * would outlive the job that took it.
     */
    public function claim(): bool;

    /**
     * The selection of 4.8: up to the limit given, of the bookings in a held state whose expiry
     * has passed, oldest id first, locked FOR UPDATE and SKIP LOCKED so a row a live booking
     * transaction is holding is read past rather than waited for. A skipped row is picked up on
     * the next pass, which is free, because the sweeper is no part of the correctness guarantee.
     *
     * An auto-approving request past its window is among them and is not excluded. The reap of
     * the write protocol's step 4 excludes it because it may only free slots; the sweep is the
     * one thing that decides such a request, so it must be able to see it.
     *
     * @return list<LapsedHold> oldest first
     */
    public function lapsed(int $limit): array;

    /**
     * The holds the clock let go: set expired, and their items stop blocking. The rows are the
     * ones this batch locked, named by their public identifiers, because nothing above the
     * repository holds a serial id.
     *
     * @param  non-empty-list<string>  $uuids
     *
     * @throws LogicException when a uuid is not one this batch's selection locked
     */
    public function expire(array $uuids): void;

    /**
     * The requests the clock granted in the owner's favour: set confirmed, with the columns of
     * an approval written beside the status. Their items go on blocking, because an approved
     * booking is a booking and the slot was already the customer's. No column given here is
     * written from the server's now, which stamps updated_at alone: a grant onto confirmed ends
     * the hold, so no ServerNowPlus is taken, and every instant given is cast as it binds.
     *
     * @param  non-empty-list<string>  $uuids
     * @param  array<string, scalar|DateTimeImmutable|null>  $columns  keyed by TransitionColumn values
     *
     * @throws LogicException when a uuid is not one this batch's selection locked
     */
    public function confirm(array $uuids, array $columns): void;

    /**
     * The requests the clock granted that are paid for online through the tenant's working
     * connection: set awaiting_payment, with the columns of an approval and a payment hold
     * written beside the status, the hold's expiry the server's own now() plus the settings
     * row's payment hold. Their items go on blocking while the hold lives, as any hold's do.
     *
     * @param  non-empty-list<string>  $uuids
     * @param  array<string, scalar|DateTimeImmutable|ServerNowPlus|null>  $columns  keyed by TransitionColumn values
     *
     * @throws LogicException when a uuid is not one this batch's selection locked
     */
    public function awaitPayment(array $uuids, array $columns): void;

    /**
     * The trail of one settlement, every row of it in one statement, written inside this
     * transaction, so a batch that rolls back leaves no trail of having happened and one that
     * commits cannot be missing its rows.
     *
     * A list and not a row at a time. This is the one job in the system that exists to work in
     * batches, and a trail written a row at a time would put five hundred round trips inside a
     * transaction holding five hundred row locks.
     *
     * @param  non-empty-list<AuditEntry>  $entries  in the order they are to be read back
     *
     * @throws LogicException when an entry names a booking this batch's selection did not lock
     */
    public function writeTrail(array $entries): void;
}
