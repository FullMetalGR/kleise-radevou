<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingCancelled;
use App\Application\Event\TransitionEvent;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Booking\Actor as PolicyActor;
use App\Domain\Booking\CancellationPolicy;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use DateTimeImmutable;

/**
 * A booking is given up: the customer through their magic link, or the tenant's own staff from
 * the booking dialog. Four states reach it, and the slot goes back to the diary in the same
 * transaction.
 *
 * It is a description handed to the shared transition implementation and not a sequence of its
 * own, so the locking, the re-read, the compare-and-set, the trail and the post-commit event
 * are the ones every transition runs.
 *
 * The guard is the cancellation policy, built from the re-read and asked with the clock.
 * Nothing the request carried reaches it: not the status the caller thought they were
 * cancelling, and not the windows a booking page rendered its buttons from.
 *
 * A refund is not this transition's business: refunds are flagged and never automatic, and
 * nothing here reads or writes a payment reference. The flag is the published record's
 * consequence, after the commit: TellTheProvider queues FlagPayment on it. A cancel that moved
 * money would be a cancel nobody could undo.
 */
final readonly class CancelBooking
{
    public function __construct(private GuardedWrite $writes) {}

    /**
     * @param  Actor  $actor  who is cancelling: the guest through their magic link, or the signed-in user acting for the business
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the status does not allow a cancel, or the customer is inside the window
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function cancel(string $uuid, Actor $actor): void
    {
        $by = PolicyActor::behind($actor);

        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            fn (LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal => $this->refusal($booking, $now, $by),
            fn (): TransitionLanding => new TransitionLanding(BookingStatus::Cancelled, [
                TransitionColumn::HoldClass->value => null,
                TransitionColumn::HoldExpiresAt->value => null,
            ]),
            AuditAction::Cancelled,
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new BookingCancelled($uuid, $from, $to, $holdClass, $actor),
        ));
    }

    /**
     * The policy on the row the lock found: the status rule for everyone, and the window for
     * the customer alone.
     *
     * Both of the window's inputs come from that same read. The start is the first segment's
     * own, and the limits are the largest cancel window among the chain's services, read from
     * the catalog now rather than copied onto the booking when it was made: an owner who
     * shortens their cancellation window means it for the bookings already in the diary, and
     * one who lengthens it has not quietly extended what a customer was promised, because the
     * window only ever decides whether the customer may do it themselves.
     *
     * The hold verdict the policy takes is the server's expiry comparison, which the cancel
     * path never reads and the reschedule path does. It is passed rather than faked because the
     * policy is one object answering two questions, and a value built with a lie in it is a lie
     * waiting for the second caller. It is the expiry alone: the blocking predicate's third
     * limb says what occupies a slot and not what may be moved, and passing the whole predicate
     * from here would have decided that question on the cancel path, where nobody was asking
     * it.
     */
    private function refusal(LockedBooking $booking, DateTimeImmutable $now, PolicyActor $by): ?TransitionRefusal
    {
        $policy = new CancellationPolicy(
            $booking->status,
            $booking->holdIsLive,
            $booking->firstStart(),
            $booking->windows(),
        );

        return $policy->mayCancel($by, $now)->refusal;
    }
}
