<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\ServerNowPlus;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingApproved;
use App\Application\Event\TransitionEvent;
use App\Application\Payment\SettlementRule;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Booking\Approval;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use Closure;
use DateTimeImmutable;

/**
 * The owner says yes to a request lodged with them: awaiting_approval to where the booking's own
 * payment mode sends it.
 *
 * It is a description handed to the shared transition implementation and not a sequence of its
 * own, so the locking, the re-read, the compare-and-set, the trail and the post-commit event are
 * the ones every transition runs. What it writes beside the status is the shared description of
 * an approval, which the sweeper writes too without going through any of this: a request granted
 * by the policy the owner set is the owner having said yes in advance.
 *
 * Nothing is decided while the description is built. Where the approval lands and the instant
 * it records are found under the lock, from the re-read, the attempt's own clock and the
 * tenant's payment connection as its row stands, so an approval run again after a reschedule
 * moved its key set stamps the attempt that commits and not one that rolled back. The one thing
 * read before the transaction opens is the length of the payment hold, a setting and not the
 * row, as a hold reads its own TTL before it writes.
 *
 * No provider is called, here or after. The payment object is made when the customer follows
 * the link in the approved message, so nothing a provider does can fail an approval.
 *
 * The slot is kept rather than released. An approved booking is a booking, and the items that
 * were blocking for the request go on blocking for the appointment.
 */
final readonly class ApproveBooking
{
    /**
     * @param  SettlementRule  $settlement  whether a booking of a mode settles without a payment: the tenant's working connection, read from its row and never from a provider
     * @param  Closure(): int  $paymentHoldHours  the settings row's payment hold, read before the transaction opens
     */
    public function __construct(
        private GuardedWrite $writes,
        private SettlementRule $settlement,
        private Closure $paymentHoldHours,
    ) {}

    /**
     * @param  Actor  $actor  who is deciding: the signed-in user pressing Approve, their signed
     *                        link, or support pressing it in a session. A decision no user made
     *                        records no user id.
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the booking is not a request waiting on the owner, or its window has closed
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function approve(string $uuid, Actor $actor): void
    {
        $hours = ($this->paymentHoldHours)();

        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            $this->guard(...),
            fn (LockedBooking $booking, DateTimeImmutable $now): TransitionLanding => self::landing(
                self::landsOn($booking->paymentMode, $this->settlement),
                Approval::by($actor, $now),
                $hours,
            ),
            AuditAction::Approved,
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new BookingApproved($uuid, $from, $to, $holdClass, $actor),
        ));
    }

    /**
     * Allowed from awaiting_approval while the request's window is still open, and from nothing
     * else.
     *
     * The window binds. A request whose hold_expires_at is at or before the transaction's now is
     * refused, even with its items still blocking and nobody having reaped it: the sweeper alone
     * settles it, by the service's on_approval_timeout. One rule on both surfaces the owner
     * decides from: the decision pages already answered the outcome page once the database said
     * the window had closed, and the admin's buttons call this directly, so a guard looser than
     * the pages would have made the same request decidable by one press and not by the other. It
     * agrees with the pending count on the rail too, which counts live requests alone, so the
     * owner is never shown a decision the button would then refuse, nor refused one it shows.
     *
     * The clock is the transaction's: the verdict is the re-read's holdIsLive, hold_expires_at
     * against the database's now() in the statement that read the row under the lock, the same
     * comparison BookingReader::holdIsLive makes for the pages and the blocking predicate makes
     * for the diary. No comparison made before the lock decides it, so a request approved a
     * second before it lapsed is approved, and the sweeper reaching it a second later finds it
     * confirmed and leaves it.
     *
     * Refused as not_approvable rather than as the confirm's hold_expired, expired included. The
     * 410 belongs to the customer's confirm alone: it is that client's signal to force its own
     * expiry flag, and the owner's admin has no such flag and no such countdown. An owner is
     * told one sentence either way.
     *
     * Nothing here asks about money, and an approval never refuses for it. Approve goes to
     * awaiting_payment, then an emailed payment link, then paid, then confirmed; a 402 belongs
     * to an online service whose booking cannot be confirmed without payment, which is the
     * customer's confirm and not the owner's decision. With no working provider, approving lands
     * on confirmed rather than stranding a booking in a state nothing could ever pay for, and a
     * refusal would leave it in awaiting_approval, which is exactly such a state.
     *
     * So where an approval lands is a question with two answers and neither of them is a
     * refusal, and it is the landing's question, landsOn, not this guard's.
     */
    private function guard(LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal
    {
        return self::refusal($booking->status, $booking->holdIsLive);
    }

    /**
     * The guard on the two facts it reads: the status and the server's verdict on the hold. It
     * is public so that the admin's list of the actions allowed now asks this function rather
     * than spelling the rule a second time; under the lock it is asked of the re-read.
     */
    public static function refusal(BookingStatus $status, bool $holdIsLive): ?TransitionRefusal
    {
        return $status === BookingStatus::AwaitingApproval && $holdIsLive ? null : TransitionRefusal::NotApprovable;
    }

    /**
     * Where an approval lands, asked of the payment mode the re-read found, of the tenant's
     * payment connection as its row stands, and of nothing a caller said: on confirmed for a
     * free or pay-on-site booking and on awaiting_payment for one paid online.
     *
     * Online lands on awaiting_payment only where the tenant has a working connection to send
     * the payment link through. Without one it lands confirmed, the degrade: an online booking
     * approved onto awaiting_payment with nothing to pay through would wait in a state nothing
     * could ever pay for. The question is the one confirm's guard asks, SettlementRule, so the
     * two can never answer it differently at the same moment. The sweeper's auto-approval asks
     * it too.
     */
    public static function landsOn(PaymentMode $mode, SettlementRule $settlement): BookingStatus
    {
        return $settlement->settlesWithoutPayment($mode) ? BookingStatus::Confirmed : BookingStatus::AwaitingPayment;
    }

    /**
     * What an approval writes where it lands. On confirmed, the four columns of an approval,
     * the hold ended. On awaiting_payment, the same stamp under a new hold: the payment class,
     * and an expiry the database writes as its own now() plus the settings row's payment hold,
     * so the clock that ends the hold is the clock that wrote it.
     */
    public static function landing(BookingStatus $status, Approval $approval, int $paymentHoldHours): TransitionLanding
    {
        return new TransitionLanding($status, self::columns($status, $approval, $paymentHoldHours));
    }

    /**
     * @return array<string, scalar|DateTimeImmutable|ServerNowPlus|null> keyed by TransitionColumn values
     */
    public static function columns(BookingStatus $status, Approval $approval, int $paymentHoldHours): array
    {
        $columns = $approval->columns();

        if ($status === BookingStatus::AwaitingPayment) {
            $columns[TransitionColumn::HoldClass->value] = HoldClass::Payment->value;
            $columns[TransitionColumn::HoldExpiresAt->value] = new ServerNowPlus($paymentHoldHours);
        }

        return $columns;
    }
}
