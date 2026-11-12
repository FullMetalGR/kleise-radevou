<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\MoveChoices;
use App\Application\Dto\SoldItem;
use App\Application\Event\BookingExpired;
use App\Application\Event\BookingRescheduled;
use App\Application\Event\TransitionEvent;
use App\Application\Event\TransitionPublisher;
use App\Application\Repository\BookingRepository;
use App\Application\Repository\BookingWriter;
use App\Application\Repository\SoldChain;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Audit\AuditKey;
use App\Domain\Booking\Actor as PolicyActor;
use App\Domain\Booking\CancellationPolicy;
use App\Domain\Booking\LockKeySet;
use App\Domain\Booking\MaskCrossing;
use App\Domain\Booking\SegmentConflict;
use App\Domain\Booking\StaffBlock;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use DateTimeImmutable;

/**
 * A booking moves to another time: the one write that both frees a range and takes one, and
 * therefore locks both. Two people move one, the customer through their magic link and the
 * business from the admin, a signed-in user or support acting in a session. Both run the one
 * sequence below; what the business adds is three choices, MoveChoices, and the customer's move
 * is the business's with all three at their defaults.
 *
 * It is deliberately not a guarded transition and does not go through GuardedWrite. It is one of
 * the three acts that stay outside the shared sequence, because it has no compare-and-set in it:
 * nothing about the status changes, and what does change is a set of rows deleted and a set
 * inserted, which is the hold's insert protocol with a delete in front of it. Forcing it through
 * a sequence built around one status update would mean teaching that sequence about items, which
 * is how a shared implementation stops being shared and starts being two implementations wearing
 * one name. What it does reuse from the guarded write is the order the two kinds of row are
 * taken in, the re-read being the only account a guard may judge, and the key-set check that
 * makes a pre-read safe to have locked from.
 *
 * Outside the transaction: the booking is read as it was sold, and the new chain is planned from
 * that through the same planner a hold uses. Same services, same order, each segment's member
 * pinned to the one it was sold to with no re-pick, or to the one the business gave it, the
 * geometry and the limits the catalog gives now, and the prices the rows fixed at hold time. The
 * reschedule dialog asks GET /bookings/{uuid}/availability, whose QueryMoveAvailability builds
 * this same chain through the same SoldChainOnCatalog and leaves the booking's own items out of
 * what is busy, as the delete below does before any overlap is checked: what is offered is what
 * is held.
 *
 * The key set is the union of where the booking is and where it is going, because the delete
 * needs the first held and the insert needs the second, and a lock over half of that protects
 * half of it. It is sorted and de-duplicated like any other, so two customers moving across one
 * another take the same pairs in the same order and queue instead of deadlocking.
 *
 * Inside the transaction, in this order: lock the union, lock the booking row second, re-read
 * under it, check the re-read's own staff-days are inside the set that was locked, judge the
 * policy on that re-read, delete the old items, reap, re-validate the new items by overlap and
 * then against the masks rebuilt on the connection, insert them blocking, re-sum the total, and
 * write the trail. The containment check comes before the guard because that is where 4.5 puts
 * it: a verdict is passed on rows whose protection has already been established, rather than on
 * rows whose protection is argued for afterwards. The delete comes before the reap and the
 * overlap checks on purpose: a move of half an hour on the same member would otherwise find the
 * booking's own old item in the way of its new one and refuse a move that conflicts with nothing
 * but itself.
 *
 * The status, the hold class and the expiry are left exactly as the lock found them. A request
 * still in front of the owner is still in front of the owner at its new time, on the same hold,
 * running out at the same moment: the customer moved their appointment, they did not settle it
 * or start it again. One column beside them moves, for a booking still waiting for its payment
 * that carries a session: payment_ref is cleared and the session is carried on the record for
 * the provider to expire after the commit, as a lapse's is, so the next visit of the pay hop
 * opens a session of its own. The total it is opened for is the one the hold fixed, since a move
 * replans at the prices the rows hold and changes no price. Nothing is refused for it, and no
 * provider is asked inside the transaction. The trail says the session was cleared, so the pay
 * hop can tell that the hold's end is already a session's end and lengthen it no further.
 *
 * What the business's choices change, and nothing else changes. A segment may go to another
 * member, keyed by its position in the chain, pinned in the sold member's place by the same
 * planner; the union then holds the new member's staff-days as well, since they are where the
 * chain is going, and the reap runs over them. Where the masks rebuilt under the lock say a
 * segment's service range crosses its member's hours or a closure, the customer's move refuses
 * as it always has, and the business's refuses with every crossing named unless the warning was
 * accepted, in which case it lands and the trail says where it overrode. Another booking in the
 * way is refused for every actor and every member, by the overlap check and then by the masks'
 * not-booked half, and the exclusion constraint stays behind both. The window binds the customer
 * alone, CancellationPolicy. So do the lead time and the horizon, which the planner applies to
 * the side the actor stands on; the grid and a start already passed bind both, as they bind the
 * manual booking, which the same planner plans. The customer is told by default; the business
 * may leave them untold, which the event carries for the listener to read.
 *
 * Every refusal leaves the booking as it was, because every one of them throws out of the
 * transaction closure and the transaction rolls back with it. The original items stand.
 */
final readonly class RescheduleBooking
{
    /**
     * The checkpoint between the mutex rows and the booking row: a worker standing here holds
     * every staff-day of the union and has not yet touched the booking.
     */
    public const string AFTER_LOCK = 'reschedule.locked';

    /**
     * After the re-read and its containment check, with both locks held and the guard not yet
     * asked.
     */
    public const string AFTER_REREAD = 'reschedule.reread';

    /**
     * How many times the union may be recomputed before the answer is busy: the attempt, and the
     * one re-run the guarded write allows a transition whose items moved.
     */
    public const int ATTEMPTS = 2;

    public function __construct(
        private SoldChain $sold,
        private HoldPlanner $planner,
        private BookingRepository $bookings,
        private TenantContext $tenant,
        private Clock $clock,
        private TransitionPublisher $events,
    ) {}

    /**
     * Moves the booking to start at the instant given, as its actor may: the customer with no
     * choices, the business, a signed-in user or support in a session, with any. The engine and a
     * job are refused here, before the booking is read, planned or locked, and so is a customer
     * carrying a choice only the business makes.
     *
     * @param  Actor  $actor  who is moving it: the guest through their magic link, a user, or support
     * @param  DateTimeImmutable  $start  the instant the chain is to start at
     * @param  MoveChoices  $choices  the business's: members by segment position, the warning accepted, the customer told
     *
     * @throws MoverRefused when the actor moves nothing, or is the customer with a choice; nothing was read or written
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws ChainUnavailable when the chain cannot be placed at that start, naming the segment, or a contended range is booked under the lock
     * @throws StaffNotEligible when a segment is given to a member who does not perform its service; nothing was locked
     * @throws SegmentConflict when a new item's range is already taken, naming the segment
     * @throws UnacceptedWarning when the business's move crosses hours or a closure and the warning was not accepted, naming every crossing
     * @throws TransitionRefused when the status does not allow a move, or the customer is inside
     *                           the window
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function reschedule(string $uuid, Actor $actor, DateTimeImmutable $start, MoveChoices $choices = new MoveChoices): void
    {
        if (! $actor->isCustomer() && $actor->userId() === null && $actor->supportOperatorId() === null) {
            throw MoverRefused::notAMover($uuid, $actor);
        }

        if ($actor->isCustomer() && ! $choices->areTheCustomers()) {
            throw MoverRefused::customerChose($uuid);
        }

        $by = PolicyActor::behind($actor);

        for ($attempt = 1; ; $attempt++) {
            try {
                $announce = $this->attempt($uuid, $actor, $by, $start, $choices);
            } catch (KeySetMoved) {
                if ($attempt >= self::ATTEMPTS) {
                    throw WriteContended::keptMoving($uuid, $attempt);
                }

                continue;
            }

            // Past this line the transaction has committed: only now do the events go out.
            foreach ($announce as $event) {
                $this->events->publish($event);
            }

            return;
        }
    }

    /**
     * One run of the move, from the pre-read to the commit, answering what the commit made true.
     *
     * The pre-read is taken again on a re-run rather than carried over, because what sent the
     * first attempt back is the booking having moved: the staff-days it was on are no longer the
     * staff-days it is on, and locking the old union a second time would fail the same way.
     *
     * @return list<TransitionEvent> in the order they are to be published
     *
     * @throws KeySetMoved when the items moved under the lock, the transaction rolled back with it
     */
    private function attempt(string $uuid, Actor $actor, PolicyActor $by, DateTimeImmutable $start, MoveChoices $choices): array
    {
        $sold = $this->sold->of($uuid) ?? throw BookingUnknown::named($uuid);
        $zone = $this->tenant->zone();
        $moving = new PlacedChain($this->planner->replan($sold, $start, $choices->staff, $by));
        $keys = LockKeySet::forBlocks(array_map(fn (SoldItem $item): StaffBlock => $item->block, $sold), $zone)
            ->union($moving->keys($zone));

        /** @var list<TransitionEvent> $announce what the commit makes true, held until it has */
        $announce = [];

        $this->bookings->transaction(function (BookingWriter $writer) use ($uuid, $actor, $by, $zone, $moving, $keys, $choices, &$announce): void {
            // A retry re-runs this closure against a rolled-back attempt, whose events never
            // happened. They are discarded here rather than after the failure, because the
            // framework's retry loop is what catches it and nothing here sees it.
            $announce = [];

            $revisions = $writer->lock($keys);
            Checkpoint::reach(self::AFTER_LOCK);

            if (! $writer->lockBooking($uuid)) {
                throw BookingUnknown::named($uuid);
            }

            $locked = $writer->readLocked();

            // The re-read's own staff-days must be inside the set that was locked, and that is
            // asked before the guard is, which is the order the guarded write puts its re-read
            // and its guard in. Only a concurrent move puts them outside it, and rows held that
            // are not the rows needed are no protection at all, neither for the delete about to
            // run over them nor for the row the guard is about to judge. Asking afterwards
            // would leave a truthful verdict resting on the booking row lock happening to cover
            // the item rows as well, which is an inference where the order makes it a fact.
            // Inside and not equal to, because the set locked is the union of two placements
            // and the booking is only ever on one of them.
            if (! $keys->covers(LockKeySet::forBlocks($locked->blocks(), $zone))) {
                throw KeySetMoved::under($uuid);
            }

            Checkpoint::reach(self::AFTER_REREAD);
            $refusal = self::refusal($locked, $this->clock->now(), $by);

            if ($refusal !== null) {
                throw TransitionRefused::because($uuid, $refusal, $locked->status);
            }

            $leaving = $locked->firstStart();
            $writer->deleteItems();

            [$windowStart, $windowEnd] = $moving->window();

            foreach ($writer->reapExpiredHolds($moving->staffIds(), $windowStart, $windowEnd) as $lapsed) {
                $writer->writeAudit(new AuditEntry($lapsed->uuid, AuditAction::Expired, Actor::system(), [
                    AuditKey::StatusFrom->value => $lapsed->status,
                    AuditKey::StatusTo->value => BookingStatus::Expired,
                    AuditKey::HoldClass->value => $lapsed->holdClass,
                ]));

                $announce[] = new BookingExpired($lapsed->uuid, $lapsed->status, BookingStatus::Expired, $lapsed->holdClass, Actor::system());
            }

            foreach ($moving->blocks() as $segment => $block) {
                if ($writer->overlapsBlockingItem($block)) {
                    throw SegmentConflict::at($segment, $block);
                }
            }

            // The customer's move refuses any crossing of the open half as it always has. The
            // business's names every crossing and lands over them only where the warning was
            // accepted; a booked range refuses inside crossings() for both.
            $overridden = [];

            if ($actor->isCustomer()) {
                $moving->assertFits($writer->availability(), $zone);
            } else {
                $overridden = $moving->crossings($writer->availability(), $zone);

                if ($overridden !== [] && ! $choices->warningAccepted) {
                    throw UnacceptedWarning::at($uuid, $overridden);
                }
            }

            $writer->insertItems($moving->items, $moving->total());

            // A held booking carries a session and never a payment, since a payment landing on a
            // live hold confirms it; so the reference a held booking carries is the session the
            // pay hop recorded, which the move expires.
            $session = $locked->status->isHeld() ? $locked->paymentRef : null;

            if ($session !== null) {
                $writer->clearSession();
            }

            // The trail says what moved and, where the move cleared a session, that it did: the
            // start the booking left and the start it took, both read off the rows rather than
            // off the request, so a payload that ever spelled an instant differently could not
            // put its spelling in the trail; and the cleared session as a fact, never its id.
            // A move by the business says besides which segments changed member, from the
            // locked rows to the inserted ones, where its accepted warning was needed, and
            // whether the customer was told; the customer's says none of the three.
            $taken = $moving->items[0]->start;
            $writer->writeAudit(new AuditEntry($uuid, AuditAction::Rescheduled, $actor, [
                AuditKey::MovedFrom->value => $leaving,
                AuditKey::StartsAt->value => $taken,
                ...($session === null ? [] : [AuditKey::SessionCleared->value => true]),
                ...($actor->isCustomer() ? [] : self::businessFacts($locked, $moving, $overridden, $choices->tellCustomer)),
            ]));

            $announce[] = new BookingRescheduled($uuid, $locked->status, $locked->holdClass, $actor, $leaving, $taken, $session, $choices->tellCustomer);

            $writer->warmAfterCommit($keys, $revisions);
        });

        return $announce;
    }

    /**
     * What the trail adds for a move by the business: each segment whose member changed, read
     * off the locked rows and the rows inserted, the crossings its accepted warning overrode, and
     * whether the customer was told. The first two only where there is one to say.
     *
     * @param  list<MaskCrossing>  $overridden
     * @return array<string, mixed>
     */
    private static function businessFacts(LockedBooking $locked, PlacedChain $moving, array $overridden, bool $told): array
    {
        $changed = [];

        foreach ($locked->blocks() as $segment => $left) {
            $taken = $moving->items[$segment]->staffId;

            if ($left->staffId !== $taken) {
                $changed[] = ['segment' => $segment, 'from' => $left->staffId, 'to' => $taken];
            }
        }

        return [
            ...($changed === [] ? [] : [AuditKey::StaffChanged->value => $changed]),
            ...($overridden === [] ? [] : [AuditKey::Overridden->value => array_map(
                fn (MaskCrossing $crossing): array => ['segment' => $crossing->segment, 'kind' => $crossing->kind],
                $overridden,
            )]),
            AuditKey::CustomerTold->value => $told,
        ];
    }

    /**
     * The policy on the row the lock found: the status rule, and the window, measured back from
     * the start the booking has now rather than the one it is moving to. A booking too late to
     * move is too late whatever time is being asked for. The window binds the customer and not
     * the business, which the policy decides from the actor it is asked for.
     *
     * The hold verdict is the server's own expiry comparison, made in the statement that read the
     * row. It is the expiry alone and deliberately not the whole blocking predicate:
     * reschedulable is "a held status whose expiry is non-null and in the future, compared the
     * same way the server compares it", and the client's affordance mirrors this guard. The
     * predicate's third limb, an approval request whose service auto-approves, cannot be mirrored
     * at all, because on_approval_timeout is manage-only and absent from the guest view, so
     * folding it in here would render a control the client could not reason about. The predicate
     * answers what occupies a slot, which is not the same question as what may be moved.
     */
    private static function refusal(LockedBooking $booking, DateTimeImmutable $now, PolicyActor $by): ?TransitionRefusal
    {
        $policy = new CancellationPolicy(
            $booking->status,
            $booking->holdIsLive,
            $booking->firstStart(),
            $booking->windows(),
        );

        return $policy->mayReschedule($by, $now)->refusal;
    }
}
