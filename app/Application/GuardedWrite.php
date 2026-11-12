<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\Repository\BookingLookup;
use App\Application\Repository\BookingRepository;
use App\Application\Repository\BookingWriter;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditKey;
use App\Domain\Booking\LockKeySet;

/**
 * The nine steps of the guarded write, once, for every transition of the compare-and-set shape.
 * Confirm, cancel, release, approve, reject and the two outcomes are descriptions handed to
 * this; none of them is a second copy of the sequence, because the sequence is where the lock
 * order, the re-read and the post-commit publish live and a copy is a chance to get one of them
 * wrong. Expiry is not one of them: the reap and the sweeper write in batches under their own
 * rules and are a different act.
 *
 * Step 1 runs outside the transaction and reads nothing but the contended ranges, so the key
 * set can be sorted before anything is locked. What it read is not evidence: a booking's status
 * from before the lock is exactly what a guard must not judge, and the pre-read has no status
 * in it to be tempted by.
 *
 * Steps 3 and 4 take the staff-day mutex rows first and the booking row second, always in that
 * order. It is the same order the reap of the write protocol's step 4 takes them in, which is
 * what keeps a hold reaping an expired booking and a transition settling one from waiting on
 * each other in opposite directions. The order is not an implementation detail of either and
 * neither may be written the other way round.
 *
 * Step 5 is two questions of the same re-read. First, do the items still touch the staff-days
 * that were locked: only a concurrent reschedule moves them, and rows held that are not the
 * rows needed are no protection at all, so the transaction rolls back and runs again from step
 * 1. Once. A second difference is contention and is answered as busy rather than tried forever.
 * Then the guard, on the re-read and the clock and nothing else.
 *
 * Step 6 writes where the transition lands, and where that is is asked here, under the lock,
 * after the key set is confirmed and the guard has allowed and before the compare-and-set. The
 * landing is a function of the same re-read and the same now the guard was shown, read once in
 * the attempt and after the key set is confirmed, and of the tenant's payment connection as its
 * row stands, which an approval asks under the lock and no provider answers. Two clocks are in
 * play. The application's is read once per attempt, after the key set is confirmed, so an
 * attempt that rolls back for a moved key set has read none of it and the attempt that runs in
 * its place reads its own. The server's now() is read in the statements themselves: by the
 * re-read's verdict on the hold on every attempt, the one rolled back for a moved key set
 * included, since the re-read comes before the key set is compared; and by an expiry a landing
 * writes from it, in the compare-and-set, which only an attempt past the key set and the guard
 * reaches. Each attempt is its own transaction with its own now(), and only the committing
 * attempt's survives. So no status and no instant written is older than the transaction that
 * writes it.
 *
 * Step 7 clears the blocking flag only where the status landed on is one the blocking predicate
 * never counts. Confirmed keeps it: a confirmed booking occupies its slot for good.
 *
 * Step 9 publishes after the repository's transaction has returned, which is after the commit
 * by construction, and never from inside the closure, because nothing in there may talk to a
 * network. A refusal throws before it and leaves nothing to publish.
 */
final readonly class GuardedWrite
{
    /**
     * The checkpoint between the mutex rows and the booking row: a test standing here holds the
     * staff-days and has not yet touched the booking, which is where a second connection can
     * still move it.
     */
    public const string AFTER_LOCK = 'guarded.locked';

    /** After the re-read, with both locks held and the guard not yet asked. */
    public const string AFTER_REREAD = 'guarded.reread';

    /**
     * How many times the key set may be recomputed before the answer is busy: the attempt, and
     * the one re-run the re-read allows it.
     */
    public const int ATTEMPTS = 2;

    public function __construct(
        private BookingLookup $bookings,
        private BookingRepository $repository,
        private TenantContext $tenant,
        private Clock $clock,
        private TransitionPublisher $events,
    ) {}

    /**
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the guard refuses what the lock found; nothing is written
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function apply(Transition $transition): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $event = $this->attempt($transition);
            } catch (KeySetMoved) {
                if ($attempt >= self::ATTEMPTS) {
                    throw WriteContended::keptMoving($transition->uuid, $attempt);
                }

                continue;
            }

            // Past this line the transaction has committed.
            $this->events->publish($event);

            return;
        }
    }

    /**
     * One run of steps 1 to 8, answering the event step 9 owes.
     *
     * @throws KeySetMoved when the items moved under the lock, the transaction rolled back with it
     */
    private function attempt(Transition $transition): TransitionEvent
    {
        $keys = $this->keysOf($transition->uuid);

        return $this->repository->transaction(function (BookingWriter $writer) use ($transition, $keys): TransitionEvent {
            $writer->lock($keys);
            Checkpoint::reach(self::AFTER_LOCK);

            if (! $writer->lockBooking($transition->uuid)) {
                throw BookingUnknown::named($transition->uuid);
            }

            $locked = $writer->readLocked();

            if (! $keys->equals(LockKeySet::forBlocks($locked->blocks(), $this->tenant->zone()))) {
                throw KeySetMoved::under($transition->uuid);
            }

            Checkpoint::reach(self::AFTER_REREAD);
            $now = $this->clock->now();
            $refusal = ($transition->guard)($locked, $now);

            if ($refusal !== null) {
                throw TransitionRefused::because($transition->uuid, $refusal, $locked->status);
            }

            $landing = ($transition->landing)($locked, $now);
            $writer->compareAndSet($locked->status, $landing->status, $landing->columns);

            if ($landing->status->releasesTheSlot()) {
                $writer->unblockItems();
            }

            $writer->writeAudit($this->trail($transition, $locked, $landing));

            return ($transition->event)($locked->status, $landing->status, $locked->holdClass, $locked);
        });
    }

    /**
     * Step 1: the staff-days the booking's items touch, in the tenant's zone, sorted and
     * de-duplicated.
     */
    private function keysOf(string $uuid): LockKeySet
    {
        $blocks = $this->bookings->blocksOf($uuid) ?? throw BookingUnknown::named($uuid);

        return LockKeySet::forBlocks($blocks, $this->tenant->zone());
    }

    /**
     * Step 8: what the trail says of any transition, which is what moved and where it landed
     * and nothing about who it was for, and any fact the transition states of itself. The hold
     * class is the one the booking was under, so a row explains which hold was settled; a booking
     * under none records none rather than a null. A fact stated under status_from, status_to or
     * hold_class is dropped before the facts are spread, so it never stands in for what the lock
     * found and the landing answered, a booking under no hold included, where nothing would be
     * written over it.
     */
    private function trail(Transition $transition, LockedBooking $locked, TransitionLanding $landing): AuditEntry
    {
        $payload = [
            AuditKey::StatusFrom->value => $locked->status,
            AuditKey::StatusTo->value => $landing->status,
        ];

        if ($locked->holdClass !== null) {
            $payload[AuditKey::HoldClass->value] = $locked->holdClass;
        }

        $facts = array_diff_key($transition->facts, array_flip([
            AuditKey::StatusFrom->value,
            AuditKey::StatusTo->value,
            AuditKey::HoldClass->value,
        ]));

        return new AuditEntry($transition->uuid, $transition->audit, $transition->actor, [...$facts, ...$payload]);
    }
}
