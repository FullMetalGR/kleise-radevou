<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Dto\LockedBooking;
use App\Application\Dto\Transition;
use App\Application\Dto\TransitionLanding;
use App\Application\Event\BookingConfirmed;
use App\Application\Event\TransitionEvent;
use App\Application\Payment\SettlementRule;
use App\Domain\Audit\Actor;
use App\Domain\Audit\AuditAction;
use App\Domain\Booking\TransitionColumn;
use App\Domain\Booking\TransitionRefusal;
use App\Domain\Enum\BookingStatus;
use App\Domain\Enum\HoldClass;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * The customer settles their own hold: pending to confirmed, the free and pay-on-site path.
 * settleAtTheHop is the same settlement reached at the pay hop where nothing can take the
 * money, and it settles a live awaiting_payment hold an approval left as well as a pending
 * one.
 *
 * It is a description handed to the shared transition implementation and not a sequence of its
 * own, so the locking, the re-read, the compare-and-set, the trail and the post-commit event
 * are the ones every transition runs.
 *
 * The slot is kept rather than released: a confirmed booking blocks for good, which is why the
 * items keep their flag and only the hold is cleared from the row.
 *
 * The plaintext manage token is passed through to the record where the caller has one. A
 * verified confirm request is the second and last moment it exists, and only the hash is
 * stored, so a listener that wants the customer's magic link in the confirmation can get it
 * nowhere else. A confirmation reached without one - the admin, a job - passes null and its
 * message carries no link, which is the honest answer rather than a broken one. The hop's
 * settlement passes the token where its road had one and null on the signed road; its record
 * says it was the hop's, and a confirmation without a token from there says where the
 * customer's link is instead, rather than carrying none.
 */
final readonly class ConfirmBooking
{
    public function __construct(
        private GuardedWrite $writes,
        private SettlementRule $settlement,
    ) {}

    /**
     * @param  Actor  $actor  who is confirming: the customer through the booking page or their magic link
     * @param  string|null  $manageToken  the plaintext credential the caller's request presented and the guard accepted, where there was one
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the booking is not a live pending hold, or must be paid for first
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function confirm(string $uuid, Actor $actor, ?string $manageToken = null): void
    {
        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            $this->guard(...),
            fn (): TransitionLanding => new TransitionLanding(BookingStatus::Confirmed, [
                TransitionColumn::HoldClass->value => null,
                TransitionColumn::HoldExpiresAt->value => null,
            ]),
            AuditAction::Confirmed,
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new BookingConfirmed($uuid, $from, $to, $holdClass, $actor, $manageToken),
        ));
    }

    /**
     * The pay hop's settlement where nothing can take the money: the settlement question has one
     * answer at every moment it is asked, and a live hold of an online booking that reaches the hop
     * with no working provider settles on site, as confirm settles it, whether or not an earlier
     * press recorded a session, whose payment_ref this leaves as it stands; that session is expired
     * after the commit, from the record this announces, where the provider can be reached and
     * declares it can. The hop asks this rather than the provider, under the token and under the
     * signed link alike, so a second visit finds the booking confirmed.
     *
     * The guard is confirm's, widened to the payment hold an approval leaves: pending or
     * awaiting_payment, live by the server's now(), and settling without payment by the rule
     * read under the lock. A connection that started working between the hop's read and this
     * lock refuses as payment_required, and the hop reads the row again.
     *
     * The record says the booking was settled at the hop, which is what the confirmation reads
     * the way it reads a payment's: the calendar file's number is the reader's, an approval
     * having perhaps sent one, and with no token the message says where the customer's link is.
     *
     * @param  string|null  $manageToken  the plaintext the token guard verified on this request, null on the signed road, which holds none
     *
     * @throws BookingUnknown when no booking of this tenant carries the identifier
     * @throws TransitionRefused when the booking is not a live hold that may settle unpaid
     * @throws WriteContended when the booking's items moved under the lock on every attempt
     */
    public function settleAtTheHop(string $uuid, #[SensitiveParameter] ?string $manageToken): void
    {
        $actor = Actor::customer();

        $this->writes->apply(new Transition(
            $uuid,
            $actor,
            fn (LockedBooking $booking): ?TransitionRefusal => $this->settles($booking, [BookingStatus::Pending, BookingStatus::AwaitingPayment]),
            fn (): TransitionLanding => new TransitionLanding(BookingStatus::Confirmed, [
                TransitionColumn::HoldClass->value => null,
                TransitionColumn::HoldExpiresAt->value => null,
            ]),
            AuditAction::Confirmed,
            fn (BookingStatus $from, BookingStatus $to, ?HoldClass $holdClass): TransitionEvent => new BookingConfirmed($uuid, $from, $to, $holdClass, $actor, $manageToken, true),
        ));
    }

    /**
     * Allowed from pending with a hold that is still live, and refused from everywhere else.
     *
     * Two refusals and not nine, because the customer is shown one sentence either way and the
     * booking app re-reads the booking rather than reasoning about a state through an error
     * body. What the two separate is the one distinction a customer can act on: a hold that ran
     * out, which means pick a time again, from a booking that has moved on to something
     * confirming is no longer part of, which means look at what it is now.
     *
     * A booking already expired is the same answer as a hold whose expiry has passed, because
     * they are the same thing at two moments: the sweeper has reached one and not the other,
     * and correctness must not depend on its having run. The live flag is the server's own
     * comparison, made where the blocking predicate makes it. A pending booking with no expiry
     * at all is not live either, which is the safe direction: the predicate does not count it
     * as blocking, so nobody is holding that slot, and confirming would hand the customer a
     * range the engine believes is free.
     *
     * Whether an online booking may settle with nothing paid is one question asked of one seam,
     * of the booking's own payment mode, copied at hold time, and never of the service's. The
     * tenant's provider answers it: yes while nothing can take the money, so an online booking
     * confirms exactly as a pay-on-site one does, and no while a connection works, which is
     * refused as payment_required.
     */
    private function guard(LockedBooking $booking, DateTimeImmutable $now): ?TransitionRefusal
    {
        return $this->settles($booking, [BookingStatus::Pending]);
    }

    /**
     * @param  list<BookingStatus>  $from  the held statuses this road settles
     */
    private function settles(LockedBooking $booking, array $from): ?TransitionRefusal
    {
        if ($booking->status === BookingStatus::Expired) {
            return TransitionRefusal::HoldExpired;
        }

        if (! in_array($booking->status, $from, true)) {
            return TransitionRefusal::NotConfirmable;
        }

        if (! $booking->holdIsLive) {
            return TransitionRefusal::HoldExpired;
        }

        return $this->settlement->settlesWithoutPayment($booking->paymentMode)
            ? null
            : TransitionRefusal::PaymentRequired;
    }
}
