<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\PaymentMode;
use DateTimeImmutable;

/**
 * A booking as our own row holds it for the payment roads: its status, the payment mode copied at
 * hold time, the reference recorded for it, whether its hold is live by the database's clock,
 * the one the sweeper reads, and whether a payment landed for it. The pay hop reads it to decide
 * what a visit is answered with, FlagPayment to decide whether a cancel's note goes, and the
 * payment webhook to tell a late payment from another notice of the one that confirmed it.
 *
 * paid is whether the paid transition's audit row (action confirmed, actor job:payment) stands,
 * which is what says a payment landed: a payment_ref alone does not, since an on-site settlement
 * at the hop leaves the session an earlier press opened, unpaid, on a confirmed booking. paidRef
 * is payment_ref where that row stands: the payment the paid transition recorded there, or, where
 * the confirming notice named none, the session's id the hop recorded, which the paid transition
 * keeps; and null where no payment landed or no reference names the one that did (a notification
 * with no payment id on a booking whose session no row recorded). paymentNamed is whether the
 * notice the paid transition confirmed on named its payment, as its audit row says: where it
 * named none, payment_ref is the session the hop recorded, or null, and nothing names the payment
 * that confirmed the booking. sessionsCleared is how many sessions moves cleared from the row,
 * counted off the move's audit rows (session_cleared): zero on a hold no session was ever
 * recorded on, whose first session the hold then follows past its own term by the provider's
 * floor; more where a move expired a recorded session, which says the hold's end is already a
 * session's end and the next session ends with it, lengthening it no further.
 */
final readonly class CheckoutState
{
    public ?string $paidRef;

    public function __construct(
        public BookingStatus $status,
        public PaymentMode $paymentMode,
        public ?string $paymentRef,
        public bool $holdIsLive,
        public ?DateTimeImmutable $holdExpiresAt,
        public bool $paid = false,
        public bool $paymentNamed = false,
        public int $sessionsCleared = 0,
    ) {
        $this->paidRef = $paid ? $paymentRef : null;
    }

    /**
     * Whether a payment notice the paid transition did not confirm is read as another notice of
     * the payment that did: a payment landed, and the notice names the payment recorded beside
     * the paid transition's audit row, or names none, or the confirming notice named none, so
     * nothing can tell the two apart. A "bought nothing" note on the payment that bought the
     * booking is the worse error, so where the confirming payment is unknown a notice naming an
     * id is read as that payment's. Any other such notice is a late payment.
     */
    public function confirmedBy(?string $paymentRef): bool
    {
        return $this->paid && ($paymentRef === null || ! $this->paymentNamed || $paymentRef === $this->paymentRef);
    }

    /**
     * A hold that cannot be confirmed until it is paid and may be paid now: a checkout hold, or
     * an approved booking awaiting its payment, of an online booking, still live.
     */
    public function payable(): bool
    {
        return $this->payableUntil() !== null;
    }

    /**
     * The end of a payable hold, the instant its session is asked to end with, or null where
     * the hold is not payable.
     */
    public function payableUntil(): ?DateTimeImmutable
    {
        return in_array($this->status, [BookingStatus::Pending, BookingStatus::AwaitingPayment], true)
            && $this->paymentMode === PaymentMode::Online
            && $this->holdIsLive
            ? $this->holdExpiresAt
            : null;
    }

    /**
     * A booking with nothing left to pay for: its hold lapsed, was released or was cancelled,
     * or it was rejected. A settled booking is not ended; it has a manage page to read.
     */
    public function ended(): bool
    {
        return in_array($this->status, [BookingStatus::Expired, BookingStatus::Cancelled, BookingStatus::Rejected], true)
            || ($this->status->isHeld() && ! $this->holdIsLive);
    }
}
