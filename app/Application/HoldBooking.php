<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\Booked;
use App\Application\Dto\Held;
use App\Application\Dto\HoldPlan;
use App\Application\Dto\HoldRequest;
use App\Application\Dto\InsertedHold;
use App\Application\Dto\Landing;
use App\Application\Dto\NewHold;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingHeld;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\Repository\BookingRepository;
use App\Application\Repository\BookingWriter;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditKey;
use App\Domain\Booking\Actor as PolicyActor;
use App\Domain\Booking\ManageToken;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Enum\BookingStatus;
use LogicException;

/**
 * The hold: the customer says which services, which people if any, and when; the planner decides
 * everything else before any transaction opens, and the eight-step write protocol re-checks the
 * decision under lock and writes it or nothing.
 *
 * Step 1 runs outside the transaction and asks the plan's own chain for its answers: every
 * item's stored block range, the key set of staff-days those ranges touch in the tenant's zone,
 * and the window step 4 will reap in, one range from the earliest block start to the latest
 * block end. The window is a superset of the union and that is fine, because step 4 reaps only
 * holds already past their expiry. The chain is the one the plan proved when it was built,
 * taken rather than rebuilt: a second one over the same items would re-run the rules that
 * object exists to establish and would establish them a second time over.
 *
 * Steps 2 to 7 run through the repository: lock, reap, re-validate every item by overlap, then
 * re-validate every item against the masks of the staff-days it touches rebuilt on the
 * transaction's connection, the cache bypassed, over the stored ranges, which the chain runs for
 * this and for the move alike; then insert all or none, keep the post-insert masks for the cache,
 * commit. Each refusal names the segment and says which of the two things was wrong, a range
 * already taken or a member not open to it, and is thrown from inside the transaction so the
 * repository rolls it back; the writer is never asked to insert. The assignment the planner made
 * is not remade here: a member found busy under lock is a conflict, since relocking a different
 * key set would break the sorted order the protocol depends on.
 *
 * The manage token is minted here, inside the transaction, and the store is handed its hash alone.
 * The plaintext leaves with the answer and is never written anywhere, so a hold that rolls back
 * takes its token with it and a hold that commits has exactly one moment to hand the customer
 * their credential. It leaves on the held record as well as in the answer, because the customer's
 * email link is the only durable copy they get and a listener re-reading the row finds only the
 * hash; a hold that rolls back announces nothing, so the token goes out only where it was also
 * handed back.
 *
 * Two checkpoints, after the lock and after the validation, let a concurrency test stop a
 * worker where the interesting interleavings are; outside the harness each costs a null check.
 * The revisions the lock returns are kept and handed to the writer with the keys after the
 * insert, so the masks it stores after the commit are keyed as the lock left the rows.
 *
 * Two transitions happen here and each writes its trail and announces itself: the hold, by
 * whoever the caller says is acting, and every hold the reap let go, by the system. Both audit
 * rows are written inside the transaction, so a conflict takes them with it; both events are
 * published after the repository's transaction has returned, which is after the commit by
 * construction, and never from inside the closure, because nothing in there may talk to a
 * network. A caller already inside a transaction is refused by the runner's first precondition,
 * so there is no path on which an event precedes its commit.
 *
 * The manual booking is the same protocol with a different landing: the plan is derived from the
 * catalog exactly once, by the same planner, and the write locks, reaps, re-validates and inserts
 * exactly as a hold does, but the row lands confirmed with no class and no expiry, the trail row
 * is the confirmation by the user who booked, and what goes out after the commit is
 * BookingConfirmed, carrying the plaintext token so the customer's confirmation holds their manage
 * link, which is the message an admin-created booking gets in place of an acknowledgement. It is
 * not a second use case because a second one would derive the plan a second time.
 *
 * What the booking carries from the surface it came through is the caller's to name, on the
 * request, Intake: the public hold's controller reads the source off the request's origin and the
 * admin's create names admin. The write stores the source and the channel on the row and the note
 * beside it inside the same transaction, and neither audit row nor any event carries any of it:
 * the trail records transitions, not what a customer wrote.
 */
final readonly class HoldBooking
{
    public const string AFTER_LOCK = 'hold.locked';

    public const string AFTER_VALIDATION = 'hold.validated';

    public function __construct(
        private HoldPlanner $planner,
        private BookingRepository $bookings,
        private TenantContext $tenant,
        private TransitionPublisher $events,
    ) {}

    /**
     * @param  Actor  $actor  who is taking the hold: the customer from the booking page, a user when the admin books by hand; the lead time and the horizon bind the customer alone
     *
     * @throws ServiceNotBookable for an unknown or inactive service, before any transaction
     * @throws ChainUnavailable when the chain cannot start there by the catalog, the clock or the masks, on either side of the lock
     * @throws SegmentConflict when the re-validation under lock finds an item's range already taken; nothing is written
     */
    public function hold(HoldRequest $request, Actor $actor): Held
    {
        return $this->execute($this->planner->plan($request, PolicyActor::behind($actor)), $actor);
    }

    /**
     * The manual booking: the chain the operator described, planned from the catalog once and
     * written under the protocol, landing confirmed with no hold. The business is booking, so the
     * lead time and the horizon do not bind it; a start already passed is still refused.
     *
     * @param  Actor  $actor  the signed-in user booking on the customer's behalf
     *
     * @throws ServiceNotBookable for an unknown or inactive service, before any transaction
     * @throws ChainUnavailable when the chain cannot start there by the catalog, the clock or the masks, on either side of the lock
     * @throws SegmentConflict when the re-validation under lock finds an item's range already taken; nothing is written
     */
    public function book(HoldRequest $request, Actor $actor): Booked
    {
        $plan = $this->planner->plan($request, PolicyActor::behind($actor));
        [$inserted, $token] = $this->write($plan, Landing::confirmed(), $actor);

        return new Booked($inserted->uuid, $plan->total, $token);
    }

    /**
     * The protocol on a plan already made, whoever made it, landing on the hold the plan
     * derived.
     *
     * @throws SegmentConflict when an item overlaps a blocking item of its staff member; nothing is written
     * @throws ChainUnavailable when an item's member cannot hold its range under the lock; nothing is written
     */
    public function execute(HoldPlan $plan, Actor $actor): Held
    {
        [$inserted, $token] = $this->write($plan, Landing::held($plan), $actor);

        return new Held(
            $inserted->uuid,
            $inserted->holdExpiresAt ?? throw new LogicException('A held landing was written with no expiry.'),
            $plan->total,
            $token,
        );
    }

    /**
     * The eight steps over a plan, landing where the landing says, and the plaintext token
     * minted for the row.
     *
     * @return array{InsertedHold, string}
     */
    private function write(HoldPlan $plan, Landing $landing, Actor $actor): array
    {
        $chain = $plan->chain;
        $blocks = $chain->blocks();
        $keys = $chain->keys($this->tenant->zone());
        $staffIds = $chain->staffIds();
        [$windowStart, $windowEnd] = $chain->window();

        /** @var list<TransitionEvent> $announce what the commit will make true, held until it has */
        $announce = [];

        $written = $this->bookings->transaction(function (BookingWriter $writer) use ($plan, $landing, $chain, $actor, $blocks, $keys, $staffIds, $windowStart, $windowEnd, &$announce): array {
            // A retry re-runs this closure against a rolled-back attempt, whose events never
            // happened. They are discarded here rather than after the failure, because the
            // framework's retry loop is what catches it and nothing here sees it.
            $announce = [];

            $revisions = $writer->lock($keys);
            Checkpoint::reach(self::AFTER_LOCK);

            foreach ($writer->reapExpiredHolds($staffIds, $windowStart, $windowEnd) as $lapsed) {
                $writer->writeAudit(new AuditEntry($lapsed->uuid, AuditAction::Expired, Actor::system(), [
                    AuditKey::StatusFrom->value => $lapsed->status,
                    AuditKey::StatusTo->value => BookingStatus::Expired,
                    AuditKey::HoldClass->value => $lapsed->holdClass,
                ]));

                $announce[] = new BookingExpired($lapsed->uuid, $lapsed->status, BookingStatus::Expired, $lapsed->holdClass, Actor::system());
            }

            foreach ($blocks as $segment => $block) {
                if ($writer->overlapsBlockingItem($block)) {
                    throw SegmentConflict::at($segment, $block);
                }
            }

            $chain->assertFits($writer->availability(), $this->tenant->zone());
            Checkpoint::reach(self::AFTER_VALIDATION);

            $token = ManageToken::generate();
            $inserted = $writer->insertHold(new NewHold(
                customer: $plan->customer,
                status: $landing->status,
                holdClass: $landing->holdClass,
                ttlMinutes: $landing->ttlMinutes,
                paymentMode: $plan->paymentMode,
                requiresApproval: $plan->requiresApproval,
                onApprovalTimeout: $plan->onApprovalTimeout,
                manageTokenHash: $token->hash(),
                total: $plan->total,
                items: $plan->items,
                intake: $plan->intake,
            ));

            // The plaintext rides on the record because this is one of the two moments it exists:
            // only the hash was stored, and the customer's email link is the only durable copy of
            // it they will ever have. It goes no further in the clear than the synchronous listener
            // that reads it, which hands it to a job whose payload is encrypted. A held landing
            // announces the hold; a settled one the confirmation, which is the message an
            // admin-created booking gets in place of an acknowledgement.
            if ($landing->isHeld()) {
                $writer->writeAudit(new AuditEntry($inserted->uuid, AuditAction::Held, $actor, [
                    AuditKey::StatusTo->value => $landing->status,
                    AuditKey::HoldClass->value => $landing->holdClass,
                    AuditKey::HoldExpiresAt->value => $inserted->holdExpiresAt,
                    AuditKey::ItemCount->value => count($plan->items),
                ]));
                $announce[] = new BookingHeld($inserted->uuid, null, $landing->status, $landing->holdClass, $actor, $token->plain);
            } else {
                $writer->writeAudit(new AuditEntry($inserted->uuid, AuditAction::Confirmed, $actor, [
                    AuditKey::StatusTo->value => $landing->status,
                    AuditKey::ItemCount->value => count($plan->items),
                ]));
                $announce[] = new BookingConfirmed($inserted->uuid, null, $landing->status, null, $actor, $token->plain);
            }

            $writer->warmAfterCommit($keys, $revisions);

            return [$inserted, $token->plain];
        });

        // Past this line the transaction has committed: only now do the events go out. A conflict
        // threw before it and left this list unread with nothing written.
        foreach ($announce as $event) {
            $this->events->publish($event);
        }

        return $written;
    }
}
