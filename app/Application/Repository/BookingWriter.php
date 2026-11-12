<?php

declare(strict_types=1);

namespace App\Application\Repository;

use App\Application\Dto\HoldItem;
use App\Application\Dto\InsertedHold;
use App\Application\Dto\LockedBooking;
use App\Application\Dto\NewHold;
use App\Application\Dto\ReapedHold;
use App\Application\Dto\ServerNowPlus;
use App\Domain\Audit\AuditEntry;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\Revisions;
use App\Domain\Booking\StaffBlock;
use App\Domain\Enum\BookingStatus;
use App\Domain\Money\Money;
use DateTimeImmutable;
use LogicException;

/**
 * Steps 3 to 6 of the write protocol, one method each, on the transaction a BookingRepository
 * opened, with the availability read the re-validation adds between steps 5 and 6 and the masks
 * kept for the cache after step 6. The use case decides the order and where it stops; the
 * writer knows the SQL. Nothing here may touch a network or the cache store, because everything
 * here runs with the mutex rows held.
 *
 * The guarded transitions share the lock and the trail and put their own four statements beside
 * the hold's: the booking row lock, the re-read under it, the compare-and-set and the unblock.
 * They address the booking once, by its public identifier, and the writer keeps the row it
 * locked; the serial id is never handed out, because nothing above the repository holds one.
 *
 * The move is the one write that runs both halves. It locks and re-reads like a transition and
 * then reaps, re-validates and inserts like a hold, with a delete of the old items between the
 * two, which is why those two statements are here and why neither of them touches the status.
 */
interface BookingWriter
{
    /**
     * Step 3: lock every staff-day of the set and bump its revision, in one statement.
     */
    public function lock(LockKeySet $keys): Revisions;

    /**
     * Step 4: expire every hold on the given staff members whose blocking items overlap the
     * window and whose expiry has passed, and clear the blocking flag on their items. Scoped by
     * staff and range, never by a day column, and skipping nothing: step 5 and the constraint
     * both read the result.
     *
     * Each hold it let go comes back as it stood before, so the use case can write its audit
     * row inside this transaction and announce it after the commit. A booking whose hold
     * lapsed is a transition like any other and its trail says why it stopped blocking. Only
     * the holds this call actually expired come back: a booking another transaction moved out
     * of reach while this one waited is left alone and is not named by a trail or an event.
     *
     * @param  non-empty-list<int>  $staffIds  the staff members whose days are locked
     * @param  DateTimeImmutable  $windowStart  the earliest block start of the new items
     * @param  DateTimeImmutable  $windowEnd  the latest block end of the new items
     * @return list<ReapedHold> the holds expired, oldest booking first
     */
    public function reapExpiredHolds(array $staffIds, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array;

    /**
     * Step 5, for one item: whether any blocking item of the same staff member overlaps its
     * block range. A plain read; nothing is locked by asking.
     */
    public function overlapsBlockingItem(StaffBlock $block): bool;

    /**
     * The availability reads on this transaction, for the step after 5: the two masks of every
     * staff-day asked for, rebuilt from the rows as they stand under the lock, the cache
     * bypassed, through the one mapping the advisory read uses.
     */
    public function availability(): AvailabilityRepository;

    /**
     * Step 6: the booking and every item, each item blocking. The store assigns the uuid and
     * the expiry and hands them back. The hold carries the manage token's hash and never the
     * token, so nothing here can hand the plaintext to anyone.
     */
    public function insertHold(NewHold $hold): InsertedHold;

    /**
     * Step 4 of the guarded write: lock the booking's own row, after the mutex rows and never
     * before them, so a transition and the reap of a competing hold take the two kinds of row
     * in one order and cannot wait on each other in opposite directions.
     *
     * The row stays locked for the rest of the transaction and is what the three methods below
     * read and write. False where no booking of this tenant carries the identifier.
     */
    public function lockBooking(string $uuid): bool;

    /**
     * Step 5: the booking as it stands under that lock, with the contended range of every item,
     * and the server's own verdict on whether its hold is still live. It is the only account of
     * the booking a guard may judge.
     *
     * @throws LogicException when nothing has been locked on this transaction
     */
    public function readLocked(): LockedBooking;

    /**
     * Step 6: the compare-and-set. One update, naming the status the re-read found in its
     * predicate and asserting that it changed exactly one row. The row is locked, so the
     * predicate cannot fail by a race; it is there because an update that matched nothing or
     * matched twice would mean the protocol is not what this thinks it is.
     *
     * @param  array<string, scalar|DateTimeImmutable|ServerNowPlus|null>  $columns  what else to write, keyed by TransitionColumn values
     *
     * @throws LogicException when nothing has been locked on this transaction
     */
    public function compareAndSet(BookingStatus $found, BookingStatus $target, array $columns): void;

    /**
     * Step 7: the locked booking's items stop blocking. Called only where the target status is
     * one the blocking predicate never counts, so a confirmed booking keeps its slot and a
     * cancelled one lets it go.
     *
     * @throws LogicException when nothing has been locked on this transaction
     */
    public function unblockItems(): void;

    /**
     * The first half of a move: the locked booking's items are deleted outright.
     *
     * Deleted and not unblocked, because the booking is not giving its slot up, it is taking a
     * different one, and the rows it is about to insert are the account of where it is. It runs
     * before the reap and before the overlap checks so that the range the booking is leaving is
     * free by the time those read the table: a move of thirty minutes on one member would
     * otherwise find the booking's own old item in the way of its new one.
     *
     * @throws LogicException when nothing has been locked on this transaction
     */
    public function deleteItems(): void;

    /**
     * The second half of a move: the new items, each blocking, and the total they come to.
     *
     * The status, the hold class and the expiry are not written and must not be. A move is the
     * one write of the lifecycle that leaves the booking exactly where it stood and changes only
     * when it is: a request still in front of the owner is still in front of the owner
     * afterwards, on the same hold, expiring at the same moment.
     *
     * The total is re-summed from the items rather than left alone, because the sum is what the
     * container's column means. The prices are the ones the rows were sold at, so today it
     * cannot move; a total assumed unchanged would be an assumption about the caller rather than
     * about the column.
     *
     * @param  non-empty-list<HoldItem>  $items  in chain order
     *
     * @throws LogicException when nothing has been locked on this transaction
     */
    public function insertItems(array $items, Money $total): void;

    /**
     * The third half of a move, and only of a move of a booking still waiting for its payment
     * that carries a session: the locked booking's payment_ref cleared, so the session it named
     * is expired after the commit and the next visit of the pay hop opens a session of its own,
     * for the total the hold fixed, which a move does not change. The status, the hold class,
     * the expiry and the sealed manage token stay as the lock found them.
     *
     * @throws LogicException when nothing has been locked on this transaction
     */
    public function clearSession(): void;

    /**
     * The audit row of one transition, step 8 of the guarded write: written inside this
     * transaction, so a transition that rolls back leaves no trail of having happened and one
     * that commits cannot be missing its row. The entry names the booking by its public
     * identifier; nothing above the repository holds a serial id.
     */
    public function writeAudit(AuditEntry $entry): void;

    /**
     * After step 6: the not-booked masks of the locked staff-days as the rows now stand,
     * read here and now, are stored under the revisions the lock returned once the transaction
     * has committed, and not before, so the next availability read of each staff-day is warm.
     * Nothing touches the store while the mutex rows are held; a transaction that rolls back
     * stores nothing.
     */
    public function warmAfterCommit(LockKeySet $keys, Revisions $revisions): void;
}
