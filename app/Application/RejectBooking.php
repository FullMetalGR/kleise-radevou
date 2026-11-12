<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingRejected;
use App\Application\Event\TransitionEvent;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use DateTimeImmutable;

/**
 * The owner says no to a request lodged with them: awaiting_approval to rejected, the other half
 * of the approval path.
 *
 * It is a description handed to the shared transition implementation and not a sequence of its
 * own, so the locking, the re-read, the compare-and-set, the trail and the post-commit event are
 * the ones every transition runs.
 *
 * The slot goes back to the diary in the same transaction: rejected is a status the blocking
 * predicate never counts, so the items stop blocking on the way through.
 */
final readonly class RejectBooking
{
    public function __construct(private GuardedWrite $writes) {}

    /**
     * The reason is optional and is stored exactly as it was given, the empty string included. An
     * empty reason must never block a rejection, so an owner who confirms the dialog with nothing
     * typed has given a reason of no words rather than given none, and the two reach the column as
     * themselves.
     *
     * It goes on the booking row and never into the audit payload. A note explaining why a request
     * was turned down may name the customer, and the trail is the one table with no erasure step,
     * which it earns by holding nothing to erase. The row is where erasure reaches it and where it
     * is withheld from the guest view.
     *
     * @param  Actor  $actor  who is deciding: the signed-in user pressing Reject, or their signed link
     * @param  string|null  $reason  the owner's note, or null where they gave none
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the booking is not a request waiting on the owner, or its window has closed
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function reject(string $uuid, Actor $actor, ?string $reason = null): void
    {
        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            $this->guard(...),
            fn (): TransitionLanding => new TransitionLanding(BookingStatus::Rejected, [
                TransitionColumn::HoldClass->value => null,
                TransitionColumn::HoldExpiresAt->value => null,
                TransitionColumn::RejectionReason->value => $reason,
            ]),
            AuditAction::Rejected,
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new BookingRejected($uuid, $from, $to, $holdClass, $actor),
        ));
    }

    /**
     * Allowed from awaiting_approval while the request's window is still open, and from nothing
     * else, which is the approval's guard for the approval's reasons: the window binds on both of
     * the owner's surfaces, so a request whose hold_expires_at is at or before the transaction's
     * now is the sweeper's to settle and not the owner's to refuse late. The verdict is the
     * re-read's, by the database's clock under the lock, and a request that was reaped reads
     * expired and is refused with every other settled state.
     *
     * Nothing here asks about money. A rejection takes nothing and owes nothing, whatever the
     * booking's payment mode, and a refund is not this transition's business in any case: refunds
     * are flagged and never automatic.
     */
    private function guard(LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal
    {
        return self::refusal($booking->status, $booking->holdIsLive);
    }

    /**
     * The guard on the two facts it reads, public for the admin's list of the actions allowed
     * now, as ApproveBooking::refusal is.
     */
    public static function refusal(BookingStatus $status, bool $holdIsLive): ?TransitionRefusal
    {
        return $status === BookingStatus::AwaitingApproval && $holdIsLive ? null : TransitionRefusal::NotRejectable;
    }
}
