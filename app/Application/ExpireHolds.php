<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LapsedHold;
use App\Application\Dto\Swept;
use App\Application\Event\BookingApproved;
use App\Application\Event\BookingExpired;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\Payment\SettlementRule;
use App\Application\Repository\HoldSweep;
use App\Application\Repository\SweepBatch;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditKey;
use App\Domain\Booking\Approval;
use App\Domain\Enum\BookingStatus;
use Closure;

/**
 * The job that tidies what the clock already decided, for one tenant.
 *
 * It is not a guarded transition and does not go through GuardedWrite. 4.5 is a sequence built
 * around one booking, one key set and one compare-and-set; this settles up to five hundred
 * bookings at a time, locks no staff-day, takes no mutex row, asks no guard and reads no
 * catalog. What it shares with 4.5 is the two rules that are not negotiable anywhere: the audit
 * row is written inside the transaction and the event is published after the commit.
 *
 * **It is no part of the correctness guarantee and must never become one.** Expired holds are
 * free by time comparison inside every availability query and every re-validation, so a sweep
 * that never ran costs nothing but tidiness and a notification. That is what lets the selection
 * read past a locked row instead of waiting for it, and what makes a refused claim on the
 * singleton lock an answer rather than a failure.
 *
 * It locks no mutex row and bumps no revision, and needs neither; one taken here would follow the
 * booking rows the selection locks, the reverse of the order the guarded write fixes. A cached
 * staff-day mask may not outlive the earliest expiry among the holds it counted as busy, and
 * every row this touches is already past its expiry: a hold it expires stopped counting at that
 * instant, and a request it grants counted busy past its window but bounded every entry that
 * counted it by that window all the same. So the cache of a staff-day this sweep writes to has
 * lapsed by the same clock reading that chose the row, and a payment hold a grant writes is
 * counted afresh, with its own expiry, by the first read after it.
 */
final readonly class ExpireHolds
{
    /**
     * How many holds one batch settles. Five hundred rows is a transaction short enough that
     * the rows it locks are not held over a long one, and long enough that a tenant with a bad
     * night is swept in a handful of them.
     */
    public const int BATCH = 500;

    /**
     * The checkpoint inside the batch transaction, taken with the singleton lock held and the
     * selection not yet made: a worker standing here is the sweep another sweep must stand
     * down against, and the rows are not yet locked.
     */
    public const string AFTER_LOCK = 'sweep.locked';

    /**
     * The name the trail and the events record this under. A decision that was nobody's records
     * no user id, and is recorded under a job actor.
     */
    private const string SWEEPER = 'sweeper';

    /**
     * @param  SettlementRule  $settlement  whether a granted request settles without a payment: the tenant's working connection, read from its row and never from a provider
     * @param  Closure(): int  $paymentHoldHours  the settings row's payment hold, read only when a granted request is to be paid for
     */
    public function __construct(
        private HoldSweep $holds,
        private Clock $clock,
        private TransitionPublisher $events,
        private SettlementRule $settlement,
        private Closure $paymentHoldHours,
    ) {}

    /**
     * Batch after batch until one finds nothing, or until another sweep of this tenant is
     * already running. Idempotent: a run that finds nothing does nothing, writes nothing and
     * publishes nothing.
     */
    public function sweep(): Swept
    {
        $swept = Swept::nothing();

        while (true) {
            /** @var list<TransitionEvent> $announce what the commit will make true, held until it has */
            $announce = [];

            $batch = $this->holds->transaction(function (SweepBatch $batch) use (&$announce): Swept {
                // A retry re-runs this closure against a rolled-back attempt, whose events
                // never happened. They are discarded here rather than after the failure,
                // because the framework's retry loop is what catches it and nothing here
                // sees it.
                $announce = [];

                return $this->settle($batch, $announce);
            });

            // Past this line the batch has committed. Nothing inside a transaction may talk to a
            // network, and a rolled-back batch announces nothing at all.
            foreach ($announce as $event) {
                $this->events->publish($event);
            }

            $swept = $swept->plus($batch);

            if (! $batch->ran || $batch->touched() === 0) {
                return $swept;
            }
        }
    }

    /**
     * One batch: the guard, the selection, and the two sides the selection splits into.
     *
     * A request whose service auto-approves on timeout has been blocking its slot since its
     * window closed, because the clock decided it in the owner's favour the moment it passed;
     * granting it writes the approval the owner's Approve would have, landing on confirmed or,
     * paid online under a working connection, on awaiting_payment under a payment hold of its
     * own, and the only place the clock's grant is written is here. Everything else the
     * selection found is a hold that stopped occupying its slot when its expiry passed, and
     * expiring it is bookkeeping.
     *
     * A batch writes up to three settlements, the expiry, the grant onto confirmed and the
     * grant onto awaiting_payment, each for the whole batch at once and never one write per
     * booking: this is the job that exists to work in batches, and it is holding five hundred
     * row locks while it does. The expiry is three statements, one for the bookings, one for
     * their items, which stop blocking, and one for the trail; each grant is two, the bookings
     * and the trail. The reads beside them are not batched: the grant asks the connection's row
     * once for every granted request paid online, and the settings row's payment hold once for
     * a batch with a grant onto awaiting_payment, both inside the batch's transaction. The
     * holds it let go are written first, the requests it granted after, and each settlement's
     * trail and announcements follow the order the selection returned its bookings, which is
     * oldest booking first.
     *
     * @param  list<TransitionEvent>  $announce
     */
    private function settle(SweepBatch $batch, array &$announce): Swept
    {
        if (! $batch->claim()) {
            return Swept::nothing();
        }

        Checkpoint::reach(self::AFTER_LOCK);

        /** @var list<LapsedHold> $released */
        $released = [];
        /** @var list<LapsedHold> $granted */
        $granted = [];

        foreach ($batch->lapsed(self::BATCH) as $hold) {
            if ($hold->grantedByTheClock()) {
                $granted[] = $hold;
            } else {
                $released[] = $hold;
            }
        }

        if ($released !== []) {
            $batch->expire(self::identifiers($released));
            $batch->writeTrail(array_map(
                fn (LapsedHold $hold): AuditEntry => $this->trail($hold, AuditAction::Expired, BookingStatus::Expired),
                $released,
            ));

            foreach ($released as $hold) {
                $announce[] = new BookingExpired($hold->uuid, $hold->status, BookingStatus::Expired, $hold->holdClass, self::actor());
            }
        }

        if ($granted !== []) {
            $this->grant($batch, $granted, $announce);
        }

        return Swept::batch(count($released), count($granted));
    }

    /**
     * The requests the clock granted, landed by the rule the owner's own Approve lands by,
     * ApproveBooking::landsOn: confirmed, or awaiting_payment for one paid online through a
     * working connection, asked of the connection's row under the batch's locks and of no
     * provider. Each side is written with the same columns of an approval the owner's Approve
     * writes, from the one description of an approval there is: nobody said yes, so approved_by
     * is the null the actor answers rather than an invented id.
     *
     * A payment hold this writes lapses like any other. The selection finds it once its expiry
     * passes and it is expired, never granted a second time: only a request awaiting approval
     * is the clock's to grant.
     *
     * @param  non-empty-list<LapsedHold>  $granted
     * @param  list<TransitionEvent>  $announce
     */
    private function grant(SweepBatch $batch, array $granted, array &$announce): void
    {
        $approval = Approval::by(self::actor(), $this->clock->now());
        $landings = [];

        foreach ($granted as $hold) {
            $landings[ApproveBooking::landsOn($hold->paymentMode, $this->settlement)->value][] = $hold;
        }

        foreach ($landings as $status => $holds) {
            $to = BookingStatus::from($status);

            if ($to === BookingStatus::AwaitingPayment) {
                $batch->awaitPayment(self::identifiers($holds), ApproveBooking::columns($to, $approval, ($this->paymentHoldHours)()));
            } else {
                $batch->confirm(self::identifiers($holds), $approval->columns());
            }

            $batch->writeTrail(array_map(
                fn (LapsedHold $hold): AuditEntry => $this->trail($hold, AuditAction::Approved, $to),
                $holds,
            ));

            foreach ($holds as $hold) {
                $announce[] = new BookingApproved($hold->uuid, $hold->status, $to, $hold->holdClass, self::actor());
            }
        }
    }

    /**
     * What the trail says of one settled hold: what moved, and the class of hold it was under,
     * which is how the notifications tell an abandoned checkout from a timed-out request.
     * Nothing about who it was for: the trail references the booking and holds nothing to
     * erase.
     */
    private function trail(LapsedHold $hold, AuditAction $action, BookingStatus $target): AuditEntry
    {
        $payload = [
            AuditKey::StatusFrom->value => $hold->status,
            AuditKey::StatusTo->value => $target,
        ];

        if ($hold->holdClass !== null) {
            $payload[AuditKey::HoldClass->value] = $hold->holdClass;
        }

        return new AuditEntry($hold->uuid, $action, self::actor(), $payload);
    }

    /**
     * @param  non-empty-list<LapsedHold>  $holds
     * @return non-empty-list<string>
     */
    private static function identifiers(array $holds): array
    {
        return array_map(fn (LapsedHold $hold): string => $hold->uuid, $holds);
    }

    /**
     * Who this job acts as, which is a question something outside it now asks. The business
     * notice goes out for a request the clock granted and not for one a person granted, and
     * both arrive as the same record, so the one thing that tells them apart is this actor;
     * answering it here keeps the name in the one place the trail and the events already
     * take it from.
     */
    public static function actor(): Actor
    {
        return Actor::job(self::SWEEPER);
    }
}
