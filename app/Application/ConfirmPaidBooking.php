<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\TransitionEvent;
use App\Application\Payment\ManageTokenSeal;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditKey;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use App\Domain\Enum\PaymentMode;
use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * The paid transition: a held booking waiting for its payment lands on confirmed because the
 * provider said the money moved. Pending under a checkout hold and awaiting_payment under a
 * payment hold both land here.
 *
 * It is a description handed to the shared transition implementation like every other, and its
 * guard is the re-read's, never the notification's: the notification says which booking and
 * nothing about its state. A booking already confirmed or past it answers AlreadySettled, which
 * is no verdict on the notice by itself: the webhook reads the row to tell another notice of the
 * payment that confirmed the booking from a late payment, and a repeat delivery of the same
 * event stops at the event record before it reaches here.
 *
 * It records the payment the notice names as the booking's payment_ref, over the session the hop
 * recorded, and compares nothing: a verified notice for this booking says money moved, and a
 * payment made at a session no row recorded is still this booking's. The note a later cancel
 * leaves names that payment. Its trail row says whether the notice named a payment, since where
 * it named none payment_ref keeps the session, or nothing, and only that row tells a later
 * notice of the payment that confirmed the booking from a late one. The row also records the
 * amount the notice says was paid, in minor units beside its currency, where the notice carried
 * one, and compares it with nothing: a move of a booking awaiting its payment expires the
 * session the hop recorded, and a payment that reached that session first still confirms, the
 * row saying what it paid.
 *
 * A refusal is no verdict on the notice: NotHeld and AlreadySettled both leave the webhook to
 * read the row, which tells another notice of the confirming payment, a no-op, from a late
 * payment. A late payment is logged; where the delivery marked the event the owner is told, and
 * the payment is flagged where the notice named one.
 *
 * The confirmation that follows carries the manage link where the pay hop saw the token: the
 * re-read holds its ciphertext, the event carries it on opened, to the send's encrypted payload,
 * and the compare-and-set clears the row in the same write.
 */
final readonly class ConfirmPaidBooking
{
    /** Who the trail names: the provider's notification, received by no person. */
    public const string ACTOR = 'payment';

    public function __construct(
        private GuardedWrite $writes,
        private ManageTokenSeal $seal,
    ) {}

    /**
     * @param  string|null  $paymentRef  the provider's id of the payment the notice says moved, recorded as the booking's payment_ref so a later note names it; null keeps the session the hop recorded
     * @param  Money|null  $amount  what the notice says was paid, recorded on the trail row and compared with nothing; null records none
     *
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function confirm(string $uuid, ?string $paymentRef = null, ?Money $amount = null): PaidOutcome
    {
        $actor = Actor::job(self::ACTOR);

        try {
            $this->writes->apply(new Transition(
                $uuid,
                $actor,
                $this->guard(...),
                fn (): TransitionLanding => new TransitionLanding(BookingStatus::Confirmed, [
                    TransitionColumn::HoldClass->value => null,
                    TransitionColumn::HoldExpiresAt->value => null,
                    ...($paymentRef === null ? [] : [TransitionColumn::PaymentRef->value => $paymentRef]),
                ]),
                AuditAction::Confirmed,
                fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass, LockedBooking $locked): TransitionEvent => new BookingConfirmed(
                    $uuid,
                    $from,
                    $to,
                    $holdClass,
                    $actor,
                    $locked->sealedToken === null ? null : $this->seal->open($locked->sealedToken),
                    true,
                ),
                [
                    AuditKey::PaymentNamed->value => $paymentRef !== null,
                    ...($amount === null ? [] : [AuditKey::PaidMinor->value => $amount->minor, AuditKey::PaidCurrency->value => $amount->currency->code]),
                ],
            ));
        } catch (BookingUnknown) {
            return PaidOutcome::Unknown;
        } catch (TransitionRefused $refused) {
            return self::settled($refused->status) ? PaidOutcome::AlreadySettled : PaidOutcome::NotHeld;
        }

        return PaidOutcome::Confirmed;
    }

    /**
     * Allowed from pending and from awaiting_payment, paid online, while the hold is live by the
     * server's own now(); refused from everywhere else. A booking already confirmed or past it
     * is refused as not confirmable, which the caller reads against the row: a no-op where the
     * notice is another of the payment that confirmed it, a late payment where it is not. A hold
     * that lapsed and has not been swept is refused as expired: correctness does not wait for
     * the sweeper.
     */
    private function guard(LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::AwaitingPayment], true) || $booking->paymentMode !== PaymentMode::Online) {
            return TransitionRefusal::NotConfirmable;
        }

        return $booking->holdIsLive ? null : TransitionRefusal::HoldExpired;
    }

    private static function settled(BookingStatus $status): bool
    {
        return in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::NoShow], true);
    }
}
